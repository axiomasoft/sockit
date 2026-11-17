<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Internal;

use Illuminate\Database\Query\Builder;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Data\GroupRef;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Groups\Enums\GroupMemberStatus;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Support\Data\ActorRef;

/**
 * Built-in {@see GroupMembershipResolver} querying live `groups` + `group_members` across active Groups-enabled panels.
 */
#[Internal]
final readonly class DatabaseGroupMembershipResolver implements GroupMembershipResolver
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
    ) {}

    #[Override]
    public function isMember(ActorContext $actor, GroupRef $group): bool
    {
        if ($actor->kind === ActorKind::Anonymous || ! $actor->actor instanceof ActorRef) {
            return false;
        }

        if (! $this->panels->isFrozen()) {
            return false;
        }

        $actorRef = $actor->actor;

        foreach ($this->panels->all() as $panel) {
            if (! $panel->hasModule('groups')) {
                continue;
            }

            $connection = $this->tables->connection($panel->id);
            $groupsTable = $this->tables->table('groups', $panel->id);
            $membersTable = $this->tables->table('group_members', $panel->id);

            $exists = $connection->table($membersTable)
                ->join($groupsTable, $groupsTable.'.id', '=', $membersTable.'.group_id')
                ->where($groupsTable.'.panel_id', $panel->id->value)
                ->where($groupsTable.'.state', 'active')
                ->where(static function (Builder $q) use ($groupsTable, $group): void {
                    $q->where($groupsTable.'.id', $group->value)
                        ->orWhere($groupsTable.'.slug', $group->value);
                })
                ->where($membersTable.'.actor_type', $actorRef->type)
                ->where($membersTable.'.actor_id', $actorRef->id)
                ->where($membersTable.'.status', GroupMemberStatus::Active->value)
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }
}
