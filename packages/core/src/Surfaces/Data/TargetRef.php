<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use Packman\Attributes\Api;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Exceptions\InvalidPayloadException;

/** Host key is opaque and lossless; incarnation prevents reuse after deletion. Panel belongs to SurfaceRef. */
#[Api(since: '1.0')]
final readonly class TargetRef
{
    public function __construct(public HostEntityRef $host, public Incarnation $incarnation) {}

    /** @return array{host:string, incarnation:int} */
    public function toWire(): array
    {
        return ['host' => $this->host->toWire(), 'incarnation' => $this->incarnation->value];
    }

    /** @param array<string, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        if (! isset($wire['host'], $wire['incarnation']) || ! is_string($wire['host']) || ! is_int($wire['incarnation'])) {
            throw new InvalidPayloadException('Invalid target reference');
        }

        return new self(host: HostEntityRef::fromWire($wire['host']), incarnation: new Incarnation($wire['incarnation']));
    }

    public function equals(self $other): bool
    {
        return $this->toWire() === $other->toWire();
    }
}
