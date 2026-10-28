<?php

declare(strict_types=1);

namespace SocKit\Surfaces;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Surfaces\Data\SurfaceDefinition;

#[Spi(kind: SpiKind::Extend, since: '1.0')]
abstract class SurfaceProvider
{
    abstract public function surface(Surface $surface): Surface;

    /** Validate provider-owned settings and the complete private graph.
     * @param array<string, SurfaceDefinition> $surfaces */
    public function validate(array $surfaces): void {}
}
