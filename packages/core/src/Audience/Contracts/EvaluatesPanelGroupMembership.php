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
 * Capability SPI returning full panel-scoped group membership verdicts (including ReadOnly/Deny narrowing
 * when the group's management surface is read-only, archived, or denied).
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface EvaluatesPanelGroupMembership extends PanelGroupMembershipResolver
{
    public function membershipVerdictInPanel(ActorContext $actor, PanelId $panel, GroupRef $group): AudienceVerdict;
}
