<?php

declare(strict_types=1);

namespace SocKit\Support\Data;

use Packman\Attributes\Api;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;

/**
 * Panel-qualified reference to a SocKit-owned domain resource.
 */
#[Api(since: '1.0')]
final readonly class ResourceRef
{
    public const string ID_PATTERN = '/\A[A-Za-z0-9-]{1,64}\z/';

    public function __construct(
        public PanelId $panel,
        public ResourceKind $kind,
        public string $id,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw InvalidPayloadException::invalid(
                'ResourceRef id must match ^[A-Za-z0-9-]{1,64}$.',
                ['id' => $id],
                '/id',
            );
        }
    }

    /** @param array<array-key, mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $panel = $wire['panel'] ?? null;
        $kind = $wire['kind'] ?? null;
        $id = $wire['id'] ?? null;

        if (! is_string($panel) || ! is_string($kind) || ! is_string($id)) {
            throw InvalidPayloadException::invalid('ResourceRef wire requires string panel, kind, and id.');
        }

        $resolvedKind = ResourceKind::tryFrom($kind);

        if ($resolvedKind === null) {
            throw InvalidPayloadException::invalid("Unknown ResourceKind [{$kind}].", ['kind' => $kind], '/kind');
        }

        return new self(new PanelId($panel), $resolvedKind, $id);
    }

    /** @return array{panel: string, kind: string, id: string} */
    public function toWire(): array
    {
        return [
            'panel' => $this->panel->value,
            'kind' => $this->kind->value,
            'id' => $this->id,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->panel->equals($other->panel)
            && $this->kind === $other->kind
            && $this->id === $other->id;
    }
}
