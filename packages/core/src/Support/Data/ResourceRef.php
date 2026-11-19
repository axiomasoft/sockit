<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final readonly class ResourceRef
{
    public function __construct(public PanelId $panel, public ResourceKind $kind, public string $id)
    {
        if (strlen($id) > 64 || ! preg_match('~^[A-Za-z0-9-]+$~D', $id)) {
            throw new InvalidPayloadException('Invalid resource id');
        }
    }

    /** @return array{panel: string, kind: string, id: string} */
    public function toWire(): array
    {
        return ['panel' => $this->panel->value, 'kind' => $this->kind->value, 'id' => $this->id];
    }

    /** @param array<string, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        if (! isset($wire['panel'], $wire['kind'], $wire['id']) || ! is_string($wire['panel']) || ! is_string($wire['kind']) || ! is_string($wire['id']) || ($kind = ResourceKind::tryFrom($wire['kind'])) === null) {
            throw new InvalidPayloadException('Invalid resource wire');
        }

        return new self(panel: new PanelId($wire['panel']), kind: $kind, id: $wire['id']);
    }

    public function equals(self $other): bool
    {
        return $this->toWire() === $other->toWire();
    }
}
