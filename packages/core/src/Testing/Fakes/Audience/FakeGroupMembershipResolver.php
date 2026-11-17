<?php

declare(strict_types=1);

namespace SocKit\Testing\Fakes\Audience;

use Closure;
use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\LocksGroupMembershipInTransaction;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Surfaces\Data\SurfaceRef;
use Throwable;

/**
 * In-memory panel-aware and transaction-fenced test double for {@see LocksGroupMembershipInTransaction}.
 */
#[Api(since: '1.0')]
final class FakeGroupMembershipResolver implements LocksGroupMembershipInTransaction
{
    /** @var array<string, true> */
    private array $members = [];

    /** @var array<string, true> */
    private array $panelMembers = [];

    private ?Throwable $failure = null;

    /** @var (Closure(PanelId, GroupRef): void)|null */
    private ?Closure $onLockCallback = null;

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

        foreach (array_keys($this->panelMembers) as $panelKey) {
            if (str_ends_with($panelKey, '|'.$actor->toWire().'|'.$ref->value)) {
                unset($this->panelMembers[$panelKey]);
            }
        }

        return $this;
    }

    public function failWith(?Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /** @param (Closure(PanelId, GroupRef): void)|null $callback */
    public function onLock(?Closure $callback): self
    {
        $this->onLockCallback = $callback;

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

    #[Override]
    public function membershipVerdictInPanel(ActorContext $actor, PanelId $panel, GroupRef $group): AudienceVerdict
    {
        return $this->isMemberInPanel($actor, $panel, $group)
            ? AudienceVerdict::allow('audience.allowed.group')
            : AudienceVerdict::deny('audience.denied.not_in_group');
    }

    /** @param array<string, AudienceVerdict>|null $preSampledSurfaceAdmissions */
    #[Override]
    public function membershipVerdictInTransaction(
        ActorContext $actor,
        PanelId $panel,
        GroupRef $group,
        ?array $preSampledSurfaceAdmissions = null,
    ): AudienceVerdict {
        return $this->membershipVerdictInPanel($actor, $panel, $group);
    }

    #[Override]
    public function lockGroupInTransaction(PanelId $panel, GroupRef $group): void
    {
        if ($this->onLockCallback instanceof Closure) {
            ($this->onLockCallback)($panel, $group);
        }
    }

    /** @param list<GroupRef> $groups */
    #[Override]
    public function lockGroupsInTransaction(PanelId $panel, array $groups): void
    {
        foreach ($groups as $group) {
            $this->lockGroupInTransaction($panel, $group);
        }
    }

    /**
     * @param  list<GroupRef>  $groups
     * @return array<string, SurfaceRef>
     */
    #[Override]
    public function managementSurfacesInPanel(PanelId $panel, array $groups): array
    {
        return [];
    }
}
