<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Internal;

use Illuminate\Database\Connection;
use Override;
use Packman\Attributes\Internal;
use Ramsey\Uuid\Uuid;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\LocksGroupMembershipInTransaction;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Groups\Enums\GroupMemberStatus;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;

/**
 * Built-in {@see LocksGroupMembershipInTransaction} resolver querying live `groups` + `group_members`
 * and enforcing the group's bound management surface incarnation, lifecycle state, and surface admission
 * strictly within the target panel isolation boundary.
 */
#[Internal]
final readonly class DatabaseGroupMembershipResolver implements LocksGroupMembershipInTransaction
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private SockitRuntime $runtime,
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
        return $this->membershipVerdictInPanel($actor, $panel, $group)->canRead();
    }

    #[Override]
    public function membershipVerdictInPanel(ActorContext $actor, PanelId $panel, GroupRef $group): AudienceVerdict
    {
        return $this->evaluate($actor, $panel, $group, callSurfacePolicy: true);
    }

    /**
     * Local in-transaction membership & management-surface check avoiding remote SPI RPC calls while holding DB locks.
     */
    #[Override]
    public function membershipVerdictInTransaction(ActorContext $actor, PanelId $panel, GroupRef $group): AudienceVerdict
    {
        return $this->evaluate($actor, $panel, $group, callSurfacePolicy: false);
    }

    #[Override]
    public function lockGroupInTransaction(PanelId $panel, GroupRef $group): void
    {
        if (! $this->panels->isFrozen() || ! $this->panels->has($panel)) {
            return;
        }

        if (! $this->panels->get($panel)->hasModule('groups')) {
            return;
        }

        $connection = $this->tables->connection($panel);
        $groupsTable = $this->tables->table('groups', $panel);

        $query = $connection->table($groupsTable)
            ->where('panel_id', $panel->value);

        if (Uuid::isValid($group->value)) {
            $query->where('id', $group->value);
        } else {
            $query->where('slug', $group->value);
        }

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $query->first();
    }

    private function evaluate(ActorContext $actor, PanelId $panel, GroupRef $group, bool $callSurfacePolicy): AudienceVerdict
    {
        if ($actor->kind === ActorKind::Anonymous || ! $actor->actor instanceof ActorRef) {
            return AudienceVerdict::deny('audience.denied.anonymous');
        }

        if (! $this->panels->isFrozen() || ! $this->panels->has($panel)) {
            return AudienceVerdict::deny('audience.unknown');
        }

        $panelDef = $this->panels->get($panel);

        if (! $panelDef->hasModule('groups')) {
            return AudienceVerdict::deny('audience.denied.not_in_group');
        }

        $actorRef = $actor->actor;
        $connection = $this->tables->connection($panel);
        $groupRow = $this->findGroupRow($connection, $panel, $group);

        if (! is_object($groupRow)) {
            return AudienceVerdict::deny('audience.denied.not_in_group');
        }

        if (SockitRows::str($groupRow, 'state') !== 'active') {
            return AudienceVerdict::deny('audience.denied.not_in_group');
        }

        $groupId = SockitRows::str($groupRow, 'id');
        $membersTable = $this->tables->table('group_members', $panel);

        $isMember = $connection->table($membersTable)
            ->where('group_id', $groupId)
            ->where('actor_type', $actorRef->type)
            ->where('actor_id', $actorRef->id)
            ->where('status', GroupMemberStatus::Active->value)
            ->exists();

        if (! $isMember) {
            return AudienceVerdict::deny('audience.denied.not_in_group');
        }

        return $this->verifyManagementSurface($connection, $panel, $groupRow, $actor, $callSurfacePolicy);
    }

    private function findGroupRow(Connection $connection, PanelId $panel, GroupRef $group): ?object
    {
        $query = $connection->table($this->tables->table('groups', $panel))
            ->where('panel_id', $panel->value);

        if (Uuid::isValid($group->value)) {
            $query->where('id', $group->value);
        } else {
            $query->where('slug', $group->value);
        }

        $row = $query->first();

        return is_object($row) ? $row : null;
    }

    private function verifyManagementSurface(
        Connection $connection,
        PanelId $panel,
        object $groupRow,
        ActorContext $actor,
        bool $callSurfacePolicy,
    ): AudienceVerdict {
        $kindStr = SockitRows::str($groupRow, 'surface_kind');
        $kind = new SurfaceKindId($kindStr);

        if (! $this->surfaces->has($panel, $kind)) {
            return AudienceVerdict::deny('audience.unknown');
        }

        $surfaceDef = $this->surfaces->get($panel, $kind);
        $host = new HostEntityRef(
            SockitRows::str($groupRow, 'surface_host_type'),
            SockitRows::str($groupRow, 'surface_host_value'),
        );
        $incarnationValue = SockitRows::int($groupRow, 'surface_incarnation', 1);

        $surfaceRow = $connection->table($this->tables->table('surface_instances', $panel))
            ->where('panel_id', $panel->value)
            ->where('kind', $kind->value)
            ->where('host_entity_type', $host->type)
            ->where('host_entity_value', $host->value)
            ->orderByDesc('incarnation')
            ->first();

        if (! is_object($surfaceRow)) {
            return AudienceVerdict::deny('audience.unknown');
        }

        if (SockitRows::int($surfaceRow, 'incarnation', 1) !== $incarnationValue) {
            return AudienceVerdict::deny('audience.denied.archived');
        }

        $state = SockitRows::str($surfaceRow, 'lifecycle_state');

        if ($state === SurfaceLifecycleState::Deleted->value) {
            return AudienceVerdict::deny('audience.denied.not_in_group');
        }

        $mgmtSurface = new SurfaceRef($panel, $kind, $host, new Incarnation($incarnationValue));

        if ($callSurfacePolicy) {
            $admit = $this->runtime->audienceGate()->admitForRead($actor, $mgmtSurface);

            if (! $admit->canRead()) {
                return $admit;
            }
        } else {
            $admit = AudienceVerdict::allow('audience.allowed.group');
        }

        if ($state === SurfaceLifecycleState::Archived->value) {
            return $surfaceDef->archiveReaders
                ? $admit->downgradeToReadOnly()
                : AudienceVerdict::deny('audience.denied.archived');
        }

        return $admit;
    }
}
