<?php

declare(strict_types=1);

namespace SocKit\Panels\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Panels\Data\PanelDefinition;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\PanelException;

/**
 * Frozen compile-time registry of SocKit storage-boundary panels.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface PanelDefinitions
{
    /** @throws PanelException */
    public function get(PanelId $id): PanelDefinition;

    public function has(PanelId $id): bool;

    /** @return array<string, PanelDefinition> */
    public function all(): array;

    public function isFrozen(): bool;
}
