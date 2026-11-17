<?php

declare(strict_types=1);

namespace SocKit\Modules\Posts\Internal;

use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\LocksTargetInTransaction;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;

/**
 * TargetResolver + TargetWriteFence slot handler contributed by PostsPlugin so Reactions
 * resolve `post:*` targets and verify privacy revision fences under same-DB row locks.
 */
#[Internal]
final readonly class PostTargetResolver implements LocksTargetInTransaction
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
    ) {}

    #[Override]
    public function supports(HostEntityRef $target): bool
    {
        return $target->type === 'post';
    }

    #[Override]
    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
    {
        if (! $this->panels->has($surface->panel) || ! $this->panels->get($surface->panel)->hasModule('posts')) {
            return TargetResolution::missing($target, 'post.module_disabled');
        }

        $connection = $this->tables->connection($surface->panel);
        $postsTable = $this->tables->table('posts', $surface->panel);
        $query = $connection->table($postsTable)
            ->where('panel_id', $surface->panel->value)
            ->where('id', $target->value);

        if ($connection->transactionLevel() > 0 && $connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! is_object($row)) {
            return TargetResolution::missing($target, 'post.not_found');
        }

        $surfaceRow = $connection->table($this->tables->table('surface_instances', $surface->panel))
            ->where('panel_id', $surface->panel->value)
            ->where('kind', $surface->kind->value)
            ->where('host_entity_type', $surface->host->type)
            ->where('host_entity_value', $surface->host->value)
            ->where('incarnation', $surface->incarnation->value)
            ->first();

        if (! is_object($surfaceRow) || SockitRows::str($surfaceRow, 'id') !== SockitRows::str($row, 'surface_instance_id')) {
            return TargetResolution::missing($target, 'post.wrong_surface');
        }

        if (SockitRows::str($surfaceRow, 'lifecycle_state') === SurfaceLifecycleState::Deleted->value) {
            return TargetResolution::hidden($target, 'content.deleted');
        }

        $state = ContentState::from(SockitRows::str($row, 'state'));

        if ($state === ContentState::Deleted) {
            return TargetResolution::hidden($target, 'content.deleted');
        }

        if ($state === ContentState::Erased) {
            return TargetResolution::hidden($target, 'content.erased');
        }

        if (! $state->isPubliclyVisible()) {
            return TargetResolution::hidden($target, 'content.'.$state->value);
        }

        return TargetResolution::available(
            target: $target,
            owner: new ActorRef(SockitRows::str($row, 'author_type'), SockitRows::str($row, 'author_id')),
            audience: AudienceSpec::fromWire(SockitRows::jsonArray($row, 'audience_wire')),
            privacyRevision: new Revision(SockitRows::int($row, 'privacy_revision', 1)),
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
            return AuthorizationDecision::deny($current->reason ?? 'target.hidden');
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
