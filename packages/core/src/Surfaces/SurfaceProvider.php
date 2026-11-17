<?php

declare(strict_types=1);

namespace SocKit\Surfaces;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Surfaces\Data\SurfaceDefinition;

/**
 * Host SPI declaring a panel-bound surface kind and optional cross-surface validation hook.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class SurfaceProvider
{
    abstract public function surface(Surface $surface): Surface;

    /** @param array<string, SurfaceDefinition> $surfaces */
    public function validate(array $surfaces): void {}
}
