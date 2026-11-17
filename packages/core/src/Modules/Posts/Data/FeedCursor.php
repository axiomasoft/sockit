<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Data;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Packman\Attributes\Api;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use Throwable;

/**
 * Keyset feed cursor encoding `(publishedAtEpochMicros, postId)` without leaking DB offsets.
 */
#[Api(since: '1.0')]
final readonly class FeedCursor
{
    public function __construct(
        public DateTimeImmutable $publishedAt,
        public string $id,
    ) {
        if (preg_match(ResourceRef::ID_PATTERN, $id) !== 1) {
            throw InvalidPayloadException::invalid('FeedCursor id is malformed.', ['id' => $id], '/cursor');
        }
    }

    public static function fromWire(string $wire): self
    {
        $decodedRaw = base64_decode($wire, true);

        if ($decodedRaw === false) {
            throw InvalidPayloadException::invalid('FeedCursor is not valid base64.', [], '/cursor');
        }

        try {
            $decoded = json_decode($decodedRaw, true, 8, JSON_THROW_ON_ERROR);
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

        return new self($publishedAt, $decoded['id']);
    }

    public function toWire(): string
    {
        $json = json_encode([
            'published_at' => $this->publishedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            'id' => $this->id,
        ], JSON_THROW_ON_ERROR);

        return base64_encode($json);
    }
}
