<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Envelope;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceInstance;
use SocKit\Surfaces\Enums\SurfaceLifecycle;

/** Narrow kernel call composition, never an implement SPI or an alternate operation pipeline. */
final readonly class SurfaceFence
{
    public function __construct(private Application $app) {}

    public function recheck(OperationHandle $owner, OperationHandle $dependency, SurfaceInstance $expected): AuthorizationDecision
    {
        try {
            // Only frozen local definitions are consulted here. Context/host resolution
            // already ran outside the envelope; do not call OperationHandles::assertCurrent.
            if ($owner->actor != $dependency->actor || $owner->panel != $dependency->panel
                || ! $owner->surface->panel->equals($dependency->surface->panel)
                || ! $expected->installation->equals($owner->actor->installation)
                || ! $expected->ref->equals($dependency->surface) || $expected->lifecycle !== SurfaceLifecycle::Live
                || ! $this->bound($owner) || ! $this->bound($dependency)) {
                return AuthorizationDecision::unknown('surface.dependency_binding_unproven');
            }
            $storage = $this->app->make(OwnerStorage::class);
            // Reentrant with the envelope's primary lock. This API must run during the
            // surface stage, before the caller acquires any host/group/content locks.
            $primary = new SurfaceInstances($storage->forLocalHandle($owner))->lock();
            if ($primary->lifecycle !== SurfaceLifecycle::Live) {
                return AuthorizationDecision::deny('surface.owner_not_live');
            }
            $nowait = $this->compare($dependency, $owner) < 0;
            $current = new SurfaceInstances($storage->forLocalHandle($dependency))->lock(nowait: $nowait);
            if ($current != $expected || $current->lifecycle !== SurfaceLifecycle::Live) {
                return AuthorizationDecision::unknown('surface.dependency_changed');
            }

            return AuthorizationDecision::allow('surface.dependency_fenced');
        } catch (\Throwable) {
            // Includes NOWAIT contention. The enclosing requireAllowed aborts/rolls back;
            // no further SQL may run in a PostgreSQL transaction after a lock error.
            return AuthorizationDecision::unknown('surface.dependency_fence_unproven');
        }
    }

    private function bound(OperationHandle $handle): bool
    {
        return $this->app->make(PanelDefinitions::class)->get($handle->surface->panel) == $handle->panel
            && $this->app->make(SurfaceDefinitions::class)->get($handle->surface->panel, $handle->surface->kind) == $handle->definition
            && $handle->surface->host->type === $handle->definition->hostType
            && in_array($handle->surface->host->type, $handle->panel->hostTypes, true);
    }

    private function compare(OperationHandle $left, OperationHandle $right): int
    {
        $a = [$left->actor->installation->id, $left->surface->panel->value, $left->surface->kind->value, $left->surface->host->type, $left->surface->host->value];
        $b = [$right->actor->installation->id, $right->surface->panel->value, $right->surface->kind->value, $right->surface->host->type, $right->surface->host->value];
        foreach ($a as $i => $part) {
            $comparison = strcmp($part, $b[$i]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }
}
