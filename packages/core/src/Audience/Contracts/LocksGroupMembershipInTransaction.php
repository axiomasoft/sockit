<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Panels\Data\PanelId;

/**
 * Marks an {@see EvaluatesPanelGroupMembership} resolver capable of locking referenced groups
 * in deterministic order and re-evaluating local DB membership state under SQL locks without remote calls.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface LocksGroupMembershipInTransaction extends EvaluatesPanelGroupMembership
{
    public function lockGroupInTransaction(PanelId $panel, GroupRef $group): void;

    public function membershipVerdictInTransaction(ActorContext $actor, PanelId $panel, GroupRef $group): AudienceVerdict;
}
