<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Internal;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Override;
use Packman\Attributes\Internal;
use Ramsey\Uuid\Uuid;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\LocksGroupMembershipInTransaction;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Data\TransactionAudienceSnapshot;
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
     *
     * @param  array<string, AudienceVerdict>|null  $preSampledSurfaceAdmissions
     */
    #[Override]
    public function membershipVerdictInTransaction(
        ActorContext $actor,
        PanelId $panel,
        GroupRef $group,
        ?array $preSampledSurfaceAdmissions = null,
    ): AudienceVerdict {
        return $this->evaluate(
            actor: $actor,
            panel: $panel,
            group: $group,
            callSurfacePolicy: false,
            preSampledSurfaceAdmissions: $preSampledSurfaceAdmissions,
        );
    }

    #[Override]
    public function lockGroupInTransaction(PanelId $panel, GroupRef $group): void
    {
        $this->lockGroupsInTransaction($panel, [$group]);
    }

    /** @param list<GroupRef> $groups */
    #[Override]
    public function lockGroupsInTransaction(PanelId $panel, array $groups): void
    {
        if (! $this->panels->isFrozen() || ! $this->panels->has($panel) || $groups === []) {
            return;
        }

        if (! $this->panels->get($panel)->hasModule('groups')) {
            return;
        }

        $connection = $this->tables->connection($panel);
        $groupsTable = $this->tables->table('groups', $panel);

        $rows = $this->findGroupRows($connection, $panel, $groups, ['id']);
        $canonicalIds = [];
        foreach ($rows as $row) {
            $canonicalIds[] = SockitRows::str($row, 'id');
        }

        $canonicalIds = array_values(array_unique($canonicalIds));
        if ($canonicalIds === []) {
            return;
        }

        sort($canonicalIds, SORT_STRING);

        $query = $connection->table($groupsTable)
            ->where('panel_id', $panel->value)
            ->whereIn('id', $canonicalIds)
            ->orderBy('id');

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $query->get();
    }

    /**
     * @param  list<GroupRef>  $groups
     * @return array<string, SurfaceRef>
     */
    #[Override]
    public function managementSurfacesInPanel(PanelId $panel, array $groups): array
    {
        if (! $this->panels->isFrozen() || ! $this->panels->has($panel) || $groups === []) {
            return [];
        }

        if (! $this->panels->get($panel)->hasModule('groups')) {
            return [];
        }

        $connection = $this->tables->connection($panel);
        $rows = $this->findGroupRows(
            $connection,
            $panel,
            $groups,
            ['surface_kind', 'surface_host_type', 'surface_host_value', 'surface_incarnation'],
        );

        $surfaces = [];
        foreach ($rows as $row) {
            $kind = new SurfaceKindId(SockitRows::str($row, 'surface_kind'));
            if (! $this->surfaces->has($panel, $kind)) {
                continue;
            }

            $surface = new SurfaceRef(
                panel: $panel,
                kind: $kind,
                host: new HostEntityRef(
                    SockitRows::str($row, 'surface_host_type'),
                    SockitRows::str($row, 'surface_host_value'),
                ),
                incarnation: new Incarnation(SockitRows::int($row, 'surface_incarnation', 1)),
            );

            $surfaces[TransactionAudienceSnapshot::surfaceKey($surface)] = $surface;
        }

        return $surfaces;
    }

    /**
     * @param  array<string, AudienceVerdict>|null  $preSampledSurfaceAdmissions
     */
    private function evaluate(
        ActorContext $actor,
        PanelId $panel,
        GroupRef $group,
        bool $callSurfacePolicy,
        ?array $preSampledSurfaceAdmissions = null,
    ): AudienceVerdict {
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

        $memberQuery = $connection->table($membersTable)
            ->where('group_id', $groupId)
            ->where('actor_type', $actorRef->type)
            ->where('actor_id', $actorRef->id)
            ->where('status', GroupMemberStatus::Active->value);
        $memberRow = $memberQuery->first();

        if (! is_object($memberRow)) {
            return AudienceVerdict::deny('audience.denied.not_in_group');
        }

        $verdict = $this->verifyManagementSurface($connection, $panel, $groupRow, $actor, $callSurfacePolicy, $preSampledSurfaceAdmissions);

        if ($callSurfacePolicy && $verdict->canRead()) {
            // External admission can revoke membership or change the group's policy, generation or binding.
            // An inconsistent sample is unknown, not proven non-membership that an exclusion could invert.
            $confirmGroupRow = $this->findGroupRow($connection, $panel, $group);
            $confirmMemberRow = $memberQuery->first();
            if (
                ! is_object($confirmGroupRow)
                || ! is_object($confirmMemberRow)
                || get_object_vars($confirmGroupRow) !== get_object_vars($groupRow)
                || get_object_vars($confirmMemberRow) !== get_object_vars($memberRow)
            ) {
                return AudienceVerdict::deny('audience.unknown');
            }
        }

        return $verdict;
    }

    /**
     * @param  list<GroupRef>  $groups
     * @param  list<string>  $columns
     * @return list<object>
     */
    private function findGroupRows(Connection $connection, PanelId $panel, array $groups, array $columns): array
    {
        $uuids = [];
        $slugs = [];

        foreach ($groups as $ref) {
            if (Uuid::isValid($ref->value)) {
                $uuids[] = $ref->value;
            } else {
                $slugs[] = $ref->value;
            }
        }

        $uuids = array_values(array_unique($uuids));
        $slugs = array_values(array_unique($slugs));

        if ($uuids === [] && $slugs === []) {
            return [];
        }

        return array_values($connection->table($this->tables->table('groups', $panel))
            ->select($columns)
            ->where('panel_id', $panel->value)
            ->where(static function (Builder $query) use ($uuids, $slugs): void {
                if ($uuids !== [] && $slugs !== []) {
                    $query->whereIn('id', $uuids)->orWhereIn('slug', $slugs);
                } elseif ($uuids !== []) {
                    $query->whereIn('id', $uuids);
                } else {
                    $query->whereIn('slug', $slugs);
                }
            })
            ->get()
            ->all());
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

    /**
     * @param  array<string, AudienceVerdict>|null  $preSampledSurfaceAdmissions
     */
    private function verifyManagementSurface(
        Connection $connection,
        PanelId $panel,
        object $groupRow,
        ActorContext $actor,
        bool $callSurfacePolicy,
        ?array $preSampledSurfaceAdmissions = null,
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

        $surfaceQuery = $connection->table($this->tables->table('surface_instances', $panel))
            ->where('panel_id', $panel->value)
            ->where('kind', $kind->value)
            ->where('host_entity_type', $host->type)
            ->where('host_entity_value', $host->value)
            ->orderByDesc('incarnation');
        $surfaceRow = $surfaceQuery->first();

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

            // Admission must not outlive the bound management surface's lifecycle or incarnation.
            $confirmSurfaceRow = $surfaceQuery->first();
            if (! is_object($confirmSurfaceRow) || get_object_vars($confirmSurfaceRow) !== get_object_vars($surfaceRow)) {
                return AudienceVerdict::deny('audience.unknown');
            }
        } else {
            $surfaceKey = TransactionAudienceSnapshot::surfaceKey($mgmtSurface);
            if ($preSampledSurfaceAdmissions === null || ! array_key_exists($surfaceKey, $preSampledSurfaceAdmissions)) {
                return AudienceVerdict::deny('audience.unknown');
            }

            $admit = $preSampledSurfaceAdmissions[$surfaceKey];

            if (! $admit->canRead()) {
                return $admit;
            }
        }

        if ($state === SurfaceLifecycleState::Archived->value) {
            return $surfaceDef->archiveReaders
                ? $admit->downgradeToReadOnly()
                : AudienceVerdict::deny('audience.denied.archived');
        }

        return $admit;
    }
}
