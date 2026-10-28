<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use SocKit\Attributes\Api;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Support\Data\WireVersion;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;

/** Trusted queue metadata, never authority or a serialized operation implementation. */
#[Api(since: '1.0')]
final readonly class OperationJobReference
{
    public function __construct(
        public SurfaceRef $surface,
        public TargetRef $target,
        public ActorRef $actor,
        public string $sourceGeneration,
        public CommandEnvelope $command,
        public WireVersion $version = new WireVersion(major: 1, minor: 0),
    ) {
        new WireVersion(major: 1, minor: 0)->requireSupported($version);
        if (! $surface->panel->equals($command->namespace->panel) || $sourceGeneration === '' || strlen($sourceGeneration) > 64 || preg_match('/[\x00-\x1f\x7f]/', $sourceGeneration)) {
            throw new InvalidPayloadException('Invalid operation job identity');
        }
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return ['version' => $this->version->toWire(), 'surface' => $this->surface->toWire(), 'target' => $this->target->toWire(),
            'actor' => $this->actor->toWire(), 'source_generation' => $this->sourceGeneration, 'namespace' => $this->command->namespace->toWire(),
            'key' => $this->command->key->value, 'digest' => $this->command->digest->toWire(), 'expected_revision' => $this->command->expectedRevision?->value];
    }

    /** @param array<string, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $keys = ['version', 'surface', 'target', 'actor', 'source_generation', 'namespace', 'key', 'digest', 'expected_revision'];
        if (count($wire) !== count($keys) || array_diff($keys, array_keys($wire)) !== []) {
            throw new InvalidPayloadException('Invalid operation job wire keys');
        }
        foreach (['version', 'actor', 'source_generation', 'namespace', 'key', 'digest'] as $key) {
            if (! is_string($wire[$key])) {
                throw new InvalidPayloadException('Invalid operation job scalar');
            }
        }
        if ($wire['expected_revision'] !== null && ! is_int($wire['expected_revision'])) {
            throw new InvalidPayloadException('Invalid operation job revision');
        }

        return new self(surface: SurfaceRef::fromWire(self::object($wire['surface'])), target: TargetRef::fromWire(self::object($wire['target'])),
            actor: ActorRef::fromWire($wire['actor']), sourceGeneration: $wire['source_generation'],
            command: new CommandEnvelope(key: new CommandKey($wire['key']), namespace: CommandNamespace::fromWire($wire['namespace']), digest: PayloadDigest::fromWire($wire['digest']),
                expectedRevision: $wire['expected_revision'] === null ? null : new Revision($wire['expected_revision'])), version: WireVersion::fromWire($wire['version']));
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidPayloadException('Invalid operation job reference');
        }

        $result = [];
        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw new InvalidPayloadException('Invalid operation job reference key');
            }
            $result[$key] = $entry;
        }

        return $result;
    }
}
