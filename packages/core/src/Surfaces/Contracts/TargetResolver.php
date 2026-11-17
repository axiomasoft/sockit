<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;

/**
 * Typed slot SPI resolving target existence, visibility, and privacy revision across host and module entities
 * without introducing direct module-to-module imports.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TargetResolver
{
    public function supports(HostEntityRef $target): bool;

    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution;
}
