<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use SocKit\Attributes\Api;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Data\WireVersion;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Sampled host authority, never bearer authorization. No body/ORM. Re-resolve at every protected entry/replay. */
#[Api(since: '1.0')]
final readonly class TargetResolution
{
    public function __construct(
        public SurfaceRef $surface,
        public TargetRef $target,
        public InstallationRef $installation,
        public AudienceVerdict $read,
        public AudienceVerdict $write,
        public TargetLifecycle $lifecycle,
        public Revision $privacyRevision,
        public string $generation,
    ) {
        if ($generation === '' || strlen($generation) > 128 || preg_match('/[\x00-\x1f\x7f]/', $generation)) {
            throw new InvalidPayloadException('Invalid target generation');
        }
    }

    /** @return array{version:string, surface:array{panel:string, kind:string, host:string, incarnation:int}, target:array{host:string, incarnation:int}, installation:string,
     * read:array{decision:string, reason:string, policy_version:string, expires_at:?string}, write:array{decision:string, reason:string, policy_version:string, expires_at:?string},
     * lifecycle:string, privacy_revision:int, generation:string} */
    public function toWire(): array
    {
        return ['version' => '1.0', 'surface' => $this->surface->toWire(), 'target' => $this->target->toWire(),
            'installation' => $this->installation->toWire(), 'read' => self::verdictWire($this->read), 'write' => self::verdictWire($this->write),
            'lifecycle' => $this->lifecycle->value, 'privacy_revision' => $this->privacyRevision->value, 'generation' => $this->generation];
    }

    /** @param array<string, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        foreach (['version', 'installation', 'lifecycle', 'generation'] as $key) {
            if (! isset($wire[$key]) || ! is_string($wire[$key])) {
                throw new InvalidPayloadException('Invalid target resolution');
            }
        }
        new WireVersion(major: 1, minor: 0)->requireSupported(WireVersion::fromWire($wire['version']));
        $lifecycle = TargetLifecycle::tryFrom($wire['lifecycle']);
        if ($lifecycle === null || ! isset($wire['privacy_revision']) || ! is_int($wire['privacy_revision'])) {
            throw new InvalidPayloadException('Invalid target lifecycle or privacy revision');
        }

        return new self(surface: SurfaceRef::fromWire(self::object($wire['surface'] ?? null)), target: TargetRef::fromWire(self::object($wire['target'] ?? null)),
            installation: InstallationRef::fromWire($wire['installation']), read: self::verdict(self::object($wire['read'] ?? null)), write: self::verdict(self::object($wire['write'] ?? null)),
            lifecycle: $lifecycle, privacyRevision: new Revision($wire['privacy_revision']), generation: $wire['generation']);
    }

    /** @return array{decision:string, reason:string, policy_version:string, expires_at:?string} */
    private static function verdictWire(AudienceVerdict $verdict): array
    {
        return ['decision' => $verdict->decision->value, 'reason' => $verdict->reason, 'policy_version' => $verdict->policyVersion,
            'expires_at' => $verdict->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')];
    }

    /** @param array<string, mixed> $wire */
    private static function verdict(array $wire): AudienceVerdict
    {
        if (! isset($wire['decision'], $wire['reason'], $wire['policy_version']) || ! is_string($wire['decision']) || ! is_string($wire['reason']) || ! is_string($wire['policy_version']) || ! array_key_exists('expires_at', $wire)) {
            throw new InvalidPayloadException('Invalid target verdict');
        }
        $decision = VerdictDecision::tryFrom($wire['decision']);
        $expiry = $wire['expires_at'];
        $date = is_string($expiry) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $expiry, new \DateTimeZone('UTC')) : null;
        if ($decision === null || ($expiry !== null && (! $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $expiry))) {
            throw new InvalidPayloadException('Invalid target verdict metadata');
        }

        return new AudienceVerdict(decision: $decision, reason: $wire['reason'], policyVersion: $wire['policy_version'], expiresAt: $date ?: null);
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidPayloadException('Expected target wire object');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidPayloadException('Expected string wire keys');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
