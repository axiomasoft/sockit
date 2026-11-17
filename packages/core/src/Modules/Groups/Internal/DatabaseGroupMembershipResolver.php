<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Internal;

use Override;
use Packman\Attributes\Internal;
use Ramsey\Uuid\Uuid;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\PanelGroupMembershipResolver;
use SocKit\Audience\Data\GroupRef;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Groups\Enums\GroupMemberStatus;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;

/**
 * Built-in {@see PanelGroupMembershipResolver} querying live `groups` + `group_members`
 * strictly within the target panel isolation boundary.
 */
#[Internal]
final readonly class DatabaseGroupMembershipResolver implements PanelGroupMembershipResolver
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
    ) {}

    #[Override]
    public function isMember(ActorContext $actor, GroupRef $group): bool
    {
        // SocKit groups are strictly panel-scoped; fail closed when no target panel identity is supplied.
        return false;
    }

    #[Override]
    public function isMemberInPanel(ActorContext $actor, PanelId $panel, GroupRef $group): bool
    {
        if ($actor->kind === ActorKind::Anonymous || ! $actor->actor instanceof ActorRef) {
            return false;
        }

        if (! $this->panels->isFrozen() || ! $this->panels->has($panel)) {
            return false;
        }

        $panelDef = $this->panels->get($panel);

        if (! $panelDef->hasModule('groups')) {
            return false;
        }

        $actorRef = $actor->actor;
        $connection = $this->tables->connection($panel);
        $groupsTable = $this->tables->table('groups', $panel);
        $membersTable = $this->tables->table('group_members', $panel);

        $groupQuery = $connection->table($groupsTable)
            ->select('id')
            ->where('panel_id', $panel->value)
            ->where('state', 'active');

        if (Uuid::isValid($group->value)) {
            $groupQuery->where('id', $group->value);
        } else {
            $groupQuery->where('slug', $group->value);
        }

        return $connection->table($membersTable)
            ->whereIn('group_id', $groupQuery)
            ->where('actor_type', $actorRef->type)
            ->where('actor_id', $actorRef->id)
            ->where('status', GroupMemberStatus::Active->value)
            ->exists();
    }
}
