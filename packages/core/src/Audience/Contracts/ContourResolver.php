<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Support\Data\HostEntityRef;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ContourResolver
{
    /** Current host facts, outside DB transactions. Throw on unknown/error. */
    public function membership(ActorContext $actor, string $level, HostEntityRef $contour): ContourMembership;

    public function enclosing(HostEntityRef $contour, string $level): ?HostEntityRef;
}
