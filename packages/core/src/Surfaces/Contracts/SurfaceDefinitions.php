<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Exceptions\SurfaceException;

/**
 * Frozen compile-time registry of panel-qualified surface definitions.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface SurfaceDefinitions
{
    /** @throws SurfaceException */
    public function get(PanelId $panel, SurfaceKindId $kind): SurfaceDefinition;

    public function has(PanelId $panel, SurfaceKindId $kind): bool;

    /** @return array<string, SurfaceDefinition> */
    public function all(): array;

    public function isFrozen(): bool;
}
