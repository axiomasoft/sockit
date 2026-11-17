<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Marks an {@see EvaluatesPanelGroupMembership} resolver capable of locking referenced groups
 * in canonical identity order and re-evaluating local DB membership state under SQL locks without remote calls.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface LocksGroupMembershipInTransaction extends EvaluatesPanelGroupMembership
{
    public function lockGroupInTransaction(PanelId $panel, GroupRef $group): void;

    /**
     * Lock all referenced groups inside the current SQL transaction ordered by canonical group identity.
     *
     * @param  list<GroupRef>  $groups
     */
    public function lockGroupsInTransaction(PanelId $panel, array $groups): void;

    /**
     * Resolve bound management surface descriptors for referenced groups without acquiring row locks.
     *
     * @param  list<GroupRef>  $groups
     * @return array<string, SurfaceRef> Keyed by canonical surface wire encoding
     */
    public function managementSurfacesInPanel(PanelId $panel, array $groups): array;

    /**
     * @param  array<string, AudienceVerdict>|null  $preSampledSurfaceAdmissions  Keyed by canonical surface wire encoding
     */
    public function membershipVerdictInTransaction(
        ActorContext $actor,
        PanelId $panel,
        GroupRef $group,
        ?array $preSampledSurfaceAdmissions = null,
    ): AudienceVerdict;
}
