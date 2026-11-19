<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;

#[Api(since: '1.0')]
final class FakeContourResolver implements ContourResolver
{
    /** @var array<string, ContourMembership> */
    private array $memberships = [];

    /** @var array<string, HostEntityRef> */
    private array $parents = [];

    private ?\Throwable $failure = null;

    public function __construct(private readonly InstallationRef $installation) {}

    public function live(ActorRef $actor, string $level, HostEntityRef $contour): self
    {
        return $this->set(actor: $actor, level: $level, contour: $contour, membership: ContourMembership::Live);
    }

    public function readOnly(ActorRef $actor, string $level, HostEntityRef $contour): self
    {
        return $this->set(actor: $actor, level: $level, contour: $contour, membership: ContourMembership::ReadOnly);
    }

    public function archived(ActorRef $actor, string $level, HostEntityRef $contour): self
    {
        return $this->set(actor: $actor, level: $level, contour: $contour, membership: ContourMembership::Archived);
    }

    public function revoke(ActorRef $actor, string $level, HostEntityRef $contour): self
    {
        return $this->set(actor: $actor, level: $level, contour: $contour, membership: ContourMembership::None);
    }

    public function enclose(HostEntityRef $child, string $level, HostEntityRef $parent): self
    {
        $this->parents[serialize([$child->toWire(), $level])] = $parent;

        return $this;
    }

    public function failWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function membership(ActorContext $actor, string $level, HostEntityRef $contour): ContourMembership
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if (! $this->installation->equals($actor->installation) || $actor->actor === null) {
            return ContourMembership::None;
        }

        return $this->memberships[serialize([$actor->actor->toWire(), $level, $contour->toWire()])] ?? ContourMembership::None;
    }

    public function enclosing(HostEntityRef $contour, string $level): ?HostEntityRef
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->parents[serialize([$contour->toWire(), $level])] ?? null;
    }

    private function set(ActorRef $actor, string $level, HostEntityRef $contour, ContourMembership $membership): self
    {
        $this->memberships[serialize([$actor->toWire(), $level, $contour->toWire()])] = $membership;

        return $this;
    }
}
