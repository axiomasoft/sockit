<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Internal\Outbox\OutboxException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Data\WireVersion;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;

/** Immutable, content-free source event. Payload values are typed resource refs, never arbitrary content.
 * Digest binds ALL immutable wire fields except digest itself, including audience, source and incarnation.
 * Event sourceGeneration is checked against frozen server config at append and dispatch, not actor facts.
 */
#[Api(since: '1.0')]
final readonly class EventEnvelope
{
    public PayloadDigest $digest;

    /** @param array<string, ResourceRef> $payload */
    public function __construct(
        public string $eventId,
        public InstallationRef $sourceInstallation,
        public PanelId $panel,
        public string $sourceGeneration,
        public SurfaceRef $surface,
        public Revision $sourceRevision,
        public Revision $privacyRevision,
        public string $eventType,
        public DateTimeImmutable $occurredAt,
        public array $payload,
        public AudienceSpec $audience,
        public ?TargetRef $target = null,
        public WireVersion $version = new WireVersion(major: 1, minor: 0),
        ?PayloadDigest $digest = null,
    ) {
        new WireVersion(major: 1, minor: 0)->requireSupported($version);
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $eventId)
            || ! $panel->equals($surface->panel) || $sourceGeneration === '' || strlen($sourceGeneration) > 64 || preg_match('/[\x00-\x1f\x7f]/', $sourceGeneration)
            || ! preg_match('/^[a-z][a-z0-9_.-]{0,119}$/D', $eventType) || $occurredAt->getOffset() !== 0) {
            throw new InvalidPayloadException('Invalid event envelope');
        }
        foreach ($payload as $key => $ref) {
            if (! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $key) || ! $ref->panel->equals($panel)) {
                throw new InvalidPayloadException('Invalid event resource reference');
            }
        }
        $computed = PayloadDigest::of(commandType: $eventType, payload: $this->unsigned());
        if ($digest !== null && ! $computed->equals($digest)) {
            throw new OutboxException(ProblemCode::EventIdentityConflict);
        }
        $this->digest = $computed;
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return [...$this->unsigned(), 'digest' => $this->digest->toWire()];
    }

    /** @return array<string, mixed> */
    private function unsigned(): array
    {
        return ['version' => $this->version->toWire(), 'event_id' => $this->eventId, 'source_installation' => $this->sourceInstallation->toWire(),
            'panel' => $this->panel->toWire(), 'source_generation' => $this->sourceGeneration, 'surface' => $this->surface->toWire(),
            'source_revision' => $this->sourceRevision->value, 'privacy_revision' => $this->privacyRevision->value, 'event_type' => $this->eventType,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.u\Z'), 'payload' => array_map(static fn (ResourceRef $ref): array => $ref->toWire(), $this->payload),
            'audience' => $this->audience->toWire(), 'target' => $this->target?->toWire()];
    }

    /** @param array<string, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $keys = ['version', 'event_id', 'source_installation', 'panel', 'source_generation', 'surface', 'source_revision', 'privacy_revision', 'event_type', 'occurred_at', 'payload', 'audience', 'target', 'digest'];
        if (count($wire) !== count($keys) || array_diff($keys, array_keys($wire)) !== []) {
            throw new InvalidPayloadException('Invalid event wire keys');
        }
        foreach (['version', 'event_id', 'source_installation', 'panel', 'source_generation', 'event_type', 'occurred_at', 'digest'] as $key) {
            if (! is_string($wire[$key])) {
                throw new InvalidPayloadException('Invalid event wire scalar');
            }
        }
        $version = WireVersion::fromWire($wire['version']);
        new WireVersion(major: 1, minor: 0)->requireSupported($version);
        if (! is_int($wire['source_revision']) || ! is_int($wire['privacy_revision'])) {
            throw new InvalidPayloadException('Invalid event revisions');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $wire['occurred_at'], new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $wire['occurred_at']) {
            throw new InvalidPayloadException('Invalid UTC event time');
        }
        $payload = [];
        foreach (self::object($wire['payload']) as $key => $value) {
            $payload[$key] = ResourceRef::fromWire(self::object($value));
        }

        return new self(eventId: $wire['event_id'], sourceInstallation: InstallationRef::fromWire($wire['source_installation']), panel: PanelId::fromWire($wire['panel']),
            sourceGeneration: $wire['source_generation'], surface: SurfaceRef::fromWire(self::object($wire['surface'])), sourceRevision: new Revision($wire['source_revision']),
            privacyRevision: new Revision($wire['privacy_revision']), eventType: $wire['event_type'], occurredAt: $date, payload: $payload,
            audience: AudienceSpec::fromWire(self::object($wire['audience'])), target: $wire['target'] === null ? null : TargetRef::fromWire(self::object($wire['target'])), version: $version, digest: PayloadDigest::fromWire($wire['digest']));
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidPayloadException('Expected event wire object');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidPayloadException('Expected event wire keys');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
