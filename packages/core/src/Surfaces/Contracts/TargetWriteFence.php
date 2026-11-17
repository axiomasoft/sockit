<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;

/**
 * Local same-database transaction fence verified after acquiring row locks and before persisting writes.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TargetWriteFence extends TargetResolver
{
    public function recheck(
        ActorContext $actor,
        SurfaceRef $surface,
        HostEntityRef $target,
        TargetResolution $prechecked,
    ): AuthorizationDecision;
}
