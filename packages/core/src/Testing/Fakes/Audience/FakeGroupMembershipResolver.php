<?php

declare(strict_types=1);

namespace SocKit\Testing\Fakes\Audience;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\PanelGroupMembershipResolver;
use SocKit\Audience\Data\GroupRef;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use Throwable;

/**
 * In-memory panel-aware test double for {@see PanelGroupMembershipResolver}.
 */
#[Api(since: '1.0')]
final class FakeGroupMembershipResolver implements PanelGroupMembershipResolver
{
    /** @var array<string, true> */
    private array $members = [];

    /** @var array<string, true> */
    private array $panelMembers = [];

    private ?Throwable $failure = null;

    public function add(ActorRef $actor, GroupRef|string $group): self
    {
        $ref = is_string($group) ? new GroupRef($group) : $group;
        $this->members[$actor->toWire().'|'.$ref->value] = true;

        return $this;
    }

    public function addForPanel(ActorRef $actor, PanelId $panel, GroupRef|string $group): self
    {
        $ref = is_string($group) ? new GroupRef($group) : $group;
        $this->panelMembers[$panel->value.'|'.$actor->toWire().'|'.$ref->value] = true;

        return $this;
    }

    public function remove(ActorRef $actor, GroupRef|string $group): self
    {
        $ref = is_string($group) ? new GroupRef($group) : $group;
        unset($this->members[$actor->toWire().'|'.$ref->value]);

        return $this;
    }

    public function failWith(?Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    #[Override]
    public function isMember(ActorContext $actor, GroupRef $group): bool
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        if (! $actor->actor instanceof ActorRef) {
            return false;
        }

        return isset($this->members[$actor->actor->toWire().'|'.$group->value]);
    }

    #[Override]
    public function isMemberInPanel(ActorContext $actor, PanelId $panel, GroupRef $group): bool
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        if (! $actor->actor instanceof ActorRef) {
            return false;
        }

        $panelKey = $panel->value.'|'.$actor->actor->toWire().'|'.$group->value;

        return isset($this->panelMembers[$panelKey]) || isset($this->members[$actor->actor->toWire().'|'.$group->value]);
    }
}
