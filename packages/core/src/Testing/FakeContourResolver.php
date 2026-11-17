<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;

/**
 * In-memory configurable test double for {@see ContourResolver}.
 */
#[Api(since: '1.0')]
final class FakeContourResolver implements ContourResolver
{
    /** @var array<string, ContourMembership> */
    private array $memberships = [];

    /** @var array<string, HostEntityRef> */
    private array $parents = [];

    public function set(ActorRef $actor, string $level, HostEntityRef $contour, ContourMembership $state): self
    {
        $this->memberships[$this->key($actor, $level, $contour)] = $state;

        return $this;
    }

    public function enclose(HostEntityRef $contour, string $level, HostEntityRef $parent): self
    {
        $this->parents[$contour->toWire().'|'.$level] = $parent;

        return $this;
    }

    public function revoke(ActorRef $actor, string $level, HostEntityRef $contour): self
    {
        unset($this->memberships[$this->key($actor, $level, $contour)]);

        return $this;
    }

    #[Override]
    public function membership(ActorContext $actor, string $level, HostEntityRef $contour): ContourMembership
    {
        if (! $actor->actor instanceof ActorRef) {
            return ContourMembership::None;
        }

        return $this->memberships[$this->key($actor->actor, $level, $contour)] ?? ContourMembership::None;
    }

    #[Override]
    public function enclosing(HostEntityRef $contour, string $level): ?HostEntityRef
    {
        return $this->parents[$contour->toWire().'|'.$level] ?? null;
    }

    private function key(ActorRef $actor, string $level, HostEntityRef $contour): string
    {
        return $actor->toWire().'|'.$level.'|'.$contour->toWire();
    }
}
