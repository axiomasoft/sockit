<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Internal;

use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\LocksTargetInTransaction;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;

/**
 * TargetResolver + TargetWriteFence slot handler contributed by GroupsPlugin for `group:*` targets
 * with same-DB transaction locking and revision fencing.
 */
#[Internal]
final readonly class GroupTargetResolver implements LocksTargetInTransaction
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
    ) {}

    #[Override]
    public function supports(HostEntityRef $target): bool
    {
        return $target->type === 'group';
    }

    #[Override]
    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
    {
        if (! $this->panels->has($surface->panel) || ! $this->panels->get($surface->panel)->hasModule('groups')) {
            return TargetResolution::missing($target, 'group.module_disabled');
        }

        $connection = $this->tables->connection($surface->panel);
        $groupsTable = $this->tables->table('groups', $surface->panel);

        $query = $connection->table($groupsTable)
            ->where('panel_id', $surface->panel->value)
            ->where('id', $target->value);

        if ($connection->transactionLevel() > 0 && $connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! is_object($row)) {
            return TargetResolution::missing($target, 'group.not_found');
        }

        if (
            SockitRows::str($row, 'surface_kind') !== $surface->kind->value
            || SockitRows::str($row, 'surface_host_type') !== $surface->host->type
            || SockitRows::str($row, 'surface_host_value') !== $surface->host->value
            || SockitRows::int($row, 'surface_incarnation', 1) !== $surface->incarnation->value
        ) {
            return TargetResolution::missing($target, 'group.wrong_surface');
        }

        if (SockitRows::str($row, 'state') !== 'active') {
            return TargetResolution::hidden($target, 'group.inactive');
        }

        $visibility = GroupVisibility::from(SockitRows::str($row, 'visibility'));
        $groupId = SockitRows::str($row, 'id');
        $audience = $visibility === GroupVisibility::Public
            ? AudienceSpec::platform()
            : AudienceSpec::group(new GroupRef($groupId));

        return TargetResolution::available(
            target: $target,
            owner: new ActorRef(SockitRows::str($row, 'created_by_type'), SockitRows::str($row, 'created_by_id')),
            audience: $audience,
            privacyRevision: new Revision(SockitRows::int($row, 'revision', 1)),
        );
    }

    #[Override]
    public function recheck(
        ActorContext $actor,
        SurfaceRef $surface,
        HostEntityRef $target,
        TargetResolution $prechecked,
    ): AuthorizationDecision {
        if (! $prechecked->privacyRevision instanceof Revision) {
            return AuthorizationDecision::unknown('reaction.target_fence_required');
        }

        $current = $this->resolve($actor, $surface, $target);

        if (! $current->isAccessible()) {
            return AuthorizationDecision::deny($current->reason ?? 'group.inactive');
        }

        if (! $current->privacyRevision instanceof Revision) {
            return AuthorizationDecision::unknown('reaction.target_fence_required');
        }

        $preAudience = $prechecked->audience instanceof AudienceSpec ? CanonicalJson::encode($prechecked->audience->toWire()) : null;
        $curAudience = $current->audience instanceof AudienceSpec ? CanonicalJson::encode($current->audience->toWire()) : null;

        if ($current->privacyRevision->value !== $prechecked->privacyRevision->value || $preAudience !== $curAudience) {
            return AuthorizationDecision::deny('target.privacy_revision_stale');
        }

        return AuthorizationDecision::allow('target.fence_verified');
    }
}
