<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use Packman\Attributes\Api;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Exceptions\InvalidPayloadException;

/**
 * Panel-qualified wire and domain reference to a surface instance.
 */
#[Api(since: '1.0')]
final readonly class SurfaceRef
{
    public function __construct(
        public PanelId $panel,
        public SurfaceKindId $kind,
        public HostEntityRef $host,
        public Incarnation $incarnation,
    ) {}

    /** @param array<array-key, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $panel = $wire['panel'] ?? null;
        $kind = $wire['kind'] ?? null;
        $host = $wire['host'] ?? null;
        $incarnation = $wire['incarnation'] ?? null;

        if (! is_string($panel) || ! is_string($kind) || ! is_string($host) || ! is_int($incarnation)) {
            throw InvalidPayloadException::invalid(
                'SurfaceRef wire requires string panel, kind, host, and int incarnation.',
            );
        }

        return new self(
            panel: new PanelId($panel),
            kind: new SurfaceKindId($kind),
            host: HostEntityRef::fromWire($host),
            incarnation: new Incarnation($incarnation),
        );
    }

    /** @return array{panel: string, kind: string, host: string, incarnation: int} */
    public function toWire(): array
    {
        return [
            'panel' => $this->panel->value,
            'kind' => $this->kind->value,
            'host' => $this->host->toWire(),
            'incarnation' => $this->incarnation->value,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->panel->equals($other->panel)
            && $this->kind->equals($other->kind)
            && $this->host->equals($other->host)
            && $this->incarnation->equals($other->incarnation);
    }
}
