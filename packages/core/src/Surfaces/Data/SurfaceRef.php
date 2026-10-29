<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use SocKit\Attributes\Api;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class SurfaceRef
{
    public function __construct(public PanelId $panel, public SurfaceKindId $kind, public HostEntityRef $host, public Incarnation $incarnation) {}

    /** @return array{panel: string, kind: string, host: string, incarnation: int} */
    public function toWire(): array
    {
        return ['panel' => $this->panel->value, 'kind' => $this->kind->value, 'host' => $this->host->toWire(), 'incarnation' => $this->incarnation->value];
    }

    /** @param array<string, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        if (! isset($wire['panel'], $wire['kind'], $wire['host'], $wire['incarnation']) || ! is_string($wire['panel']) || ! is_string($wire['kind']) || ! is_string($wire['host']) || ! is_int($wire['incarnation'])) {
            throw new InvalidPayloadException('Invalid surface wire');
        }

        return new self(panel: new PanelId($wire['panel']), kind: new SurfaceKindId($wire['kind']), host: HostEntityRef::fromWire($wire['host']), incarnation: new Incarnation($wire['incarnation']));
    }

    public function equals(self $other): bool
    {
        return $this->toWire() === $other->toWire();
    }
}
