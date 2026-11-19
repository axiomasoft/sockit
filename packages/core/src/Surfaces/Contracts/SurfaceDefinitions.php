<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceKindId;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface SurfaceDefinitions
{
    public function get(PanelId $panel, SurfaceKindId $kind): SurfaceDefinition;

    public function has(PanelId $panel, SurfaceKindId $kind): bool;

    /** @return array<string, SurfaceDefinition> Keys are panel/kind. */
    public function all(): array;
}
