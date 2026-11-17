<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Data;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;
use Throwable;

/**
 * Signed, viewer/surface/installation-bound keyset feed cursor encoding `(publishedAt, postId)` with expiry.
 */
#[Api(since: '1.0')]
final readonly class FeedCursor
{
    public function __construct(
        public DateTimeImmutable $publishedAt,
        public string $id,
        public ?string $token = null,
    ) {
        if (preg_match(ResourceRef::ID_PATTERN, $id) !== 1) {
            throw InvalidPayloadException::invalid('FeedCursor id is malformed.', ['id' => $id], '/cursor');
        }
    }

    public static function issue(
        DateTimeImmutable $publishedAt,
        string $id,
        SurfaceRef $surface,
        ActorContext $viewer,
        string $signingKey,
        DateTimeImmutable $expiresAt,
    ): self {
        $claims = [
            'v' => 1,
            'published_at' => $publishedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            'id' => $id,
            'panel' => $surface->panel->value,
            'surface' => $surface->toWire(),
            'installation' => $viewer->installation->id,
            'viewer' => self::viewerKey($viewer),
            'exp' => $expiresAt->getTimestamp(),
        ];

        $mac = hash_hmac('sha256', CanonicalJson::encode($claims), $signingKey);
        $envelope = [...$claims, 'mac' => $mac];

        try {
            $json = json_encode($envelope, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidPayloadException::invalid('Failed to encode FeedCursor: '.$exception->getMessage(), [], '/cursor');
        }

        return new self($publishedAt, $id, base64_encode($json));
    }

    public static function fromWire(string $wire): self
    {
        $decodedRaw = base64_decode($wire, true);

        if ($decodedRaw === false) {
            throw InvalidPayloadException::invalid('FeedCursor is not valid base64.', [], '/cursor');
        }

        try {
            $decoded = json_decode($decodedRaw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidPayloadException::invalid('FeedCursor JSON is malformed.', [], '/cursor');
        }

        if (! is_array($decoded) || ! is_string($decoded['published_at'] ?? null) || ! is_string($decoded['id'] ?? null)) {
            throw InvalidPayloadException::invalid('FeedCursor requires string published_at and id.', [], '/cursor');
        }

        try {
            $publishedAt = new DateTimeImmutable($decoded['published_at']);
        } catch (Throwable) {
            throw InvalidPayloadException::invalid('FeedCursor published_at is not a valid timestamp.', [], '/cursor');
        }

        $token = isset($decoded['mac']) && is_string($decoded['mac']) ? $wire : null;

        return new self($publishedAt, $decoded['id'], $token);
    }

    public function verifyForContext(
        SurfaceRef $surface,
        ActorContext $viewer,
        string $signingKey,
        DateTimeImmutable $now,
    ): void {
        if ($this->token === null || $this->token === '') {
            throw InvalidPayloadException::invalid('FeedCursor must be signed and context-bound.', [], '/cursor');
        }

        $raw = base64_decode($this->token, true);
        if ($raw === false) {
            throw InvalidPayloadException::invalid('FeedCursor token is malformed.', [], '/cursor');
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidPayloadException::invalid('FeedCursor token JSON is malformed.', [], '/cursor');
        }

        if (
            ! is_array($decoded)
            || ($decoded['v'] ?? null) !== 1
            || ! is_string($decoded['published_at'] ?? null)
            || ! is_string($decoded['id'] ?? null)
            || ! is_string($decoded['panel'] ?? null)
            || ! is_array($decoded['surface'] ?? null)
            || ! is_string($decoded['installation'] ?? null)
            || ! is_string($decoded['viewer'] ?? null)
            || ! is_int($decoded['exp'] ?? null)
            || ! is_string($decoded['mac'] ?? null)
        ) {
            throw InvalidPayloadException::invalid('FeedCursor claims are incomplete or invalid.', [], '/cursor');
        }

        $claims = [
            'v' => 1,
            'published_at' => $decoded['published_at'],
            'id' => $decoded['id'],
            'panel' => $decoded['panel'],
            'surface' => $decoded['surface'],
            'installation' => $decoded['installation'],
            'viewer' => $decoded['viewer'],
            'exp' => $decoded['exp'],
        ];

        $expectedMac = hash_hmac('sha256', CanonicalJson::encode($claims), $signingKey);
        if (! hash_equals($expectedMac, $decoded['mac'])) {
            throw InvalidPayloadException::invalid('FeedCursor signature is invalid.', [], '/cursor');
        }

        if (
            $decoded['id'] !== $this->id
            || $decoded['published_at'] !== $this->publishedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP')
            || $decoded['panel'] !== $surface->panel->value
            || CanonicalJson::encode($decoded['surface']) !== CanonicalJson::encode($surface->toWire())
            || $decoded['installation'] !== $viewer->installation->id
            || $decoded['viewer'] !== self::viewerKey($viewer)
        ) {
            throw InvalidPayloadException::invalid('FeedCursor does not match current feed panel, surface, or viewer.', [], '/cursor');
        }

        if ($decoded['exp'] < $now->getTimestamp()) {
            throw InvalidPayloadException::invalid('FeedCursor has expired.', [], '/cursor');
        }
    }

    public function toWire(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        try {
            $json = json_encode([
                'published_at' => $this->publishedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
                'id' => $this->id,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidPayloadException::invalid('Failed to encode FeedCursor: '.$exception->getMessage(), [], '/cursor');
        }

        return base64_encode($json);
    }

    private static function viewerKey(ActorContext $viewer): string
    {
        return $viewer->kind->value
            .':'.$viewer->installation->id
            .':'.($viewer->actor?->toWire() ?? 'anon')
            .':'.($viewer->onBehalfOf?->toWire() ?? '');
    }
}
