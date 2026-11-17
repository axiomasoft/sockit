<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use Override;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Support\Data\HostEntityRef;

final class HostProductionContourResolver implements ContourResolver
{
    /** @var array<string, ContourMembership> */
    private array $memberships = [];

    public function grant(ActorContext $actor, string $level, HostEntityRef $contour): void
    {
        $key = ($actor->actor?->toWire() ?? 'anon').'|'.$level.'|'.$contour->toWire();
        $this->memberships[$key] = ContourMembership::Live;
    }

    #[Override]
    public function membership(ActorContext $actor, string $level, HostEntityRef $contour): ContourMembership
    {
        $key = ($actor->actor?->toWire() ?? 'anon').'|'.$level.'|'.$contour->toWire();

        return $this->memberships[$key] ?? ContourMembership::Denied;
    }

    #[Override]
    public function enclosing(HostEntityRef $contour, string $level): ?HostEntityRef
    {
        return null;
    }
}
