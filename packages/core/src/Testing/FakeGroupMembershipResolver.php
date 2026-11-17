<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Data\GroupRef;
use SocKit\Support\Data\ActorRef;

/**
 * In-memory test double for {@see GroupMembershipResolver}.
 */
#[Api(since: '1.0')]
final class FakeGroupMembershipResolver implements GroupMembershipResolver
{
    /** @var array<string, true> */
    private array $members = [];

    public function add(ActorRef $actor, GroupRef|string $group): self
    {
        $ref = is_string($group) ? new GroupRef($group) : $group;
        $this->members[$actor->toWire().'|'.$ref->value] = true;

        return $this;
    }

    public function remove(ActorRef $actor, GroupRef|string $group): self
    {
        $ref = is_string($group) ? new GroupRef($group) : $group;
        unset($this->members[$actor->toWire().'|'.$ref->value]);

        return $this;
    }

    #[Override]
    public function isMember(ActorContext $actor, GroupRef $group): bool
    {
        if (! $actor->actor instanceof ActorRef) {
            return false;
        }

        return isset($this->members[$actor->actor->toWire().'|'.$group->value]);
    }
}
