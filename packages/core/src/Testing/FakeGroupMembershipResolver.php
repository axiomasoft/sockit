<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Data\GroupRef;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;

#[Api(since: '1.0')]
final class FakeGroupMembershipResolver implements GroupMembershipResolver
{
    /** @var array<string, bool> */
    private array $members = [];

    private ?\Throwable $failure = null;

    public function __construct(private readonly InstallationRef $installation) {}

    public function member(ActorRef $actor, GroupRef $group): self
    {
        $this->members[serialize([$actor->toWire(), $group->value])] = true;

        return $this;
    }

    public function revoke(ActorRef $actor, GroupRef $group): self
    {
        unset($this->members[serialize([$actor->toWire(), $group->value])]);

        return $this;
    }

    public function failWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function isMember(ActorContext $actor, GroupRef $group): bool
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $actor->actor !== null && $actor->installation->equals($this->installation)
            && isset($this->members[serialize([$actor->actor->toWire(), $group->value])]);
    }
}
