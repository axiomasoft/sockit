<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\GroupRef;
use SocKit\Panels\Data\PanelId;

/**
 * Capability SPI extending {@see GroupMembershipResolver} with strict target-panel scoping
 * so group identities and slugs never leak across panel isolation boundaries.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PanelGroupMembershipResolver extends GroupMembershipResolver
{
    public function isMemberInPanel(ActorContext $actor, PanelId $panel, GroupRef $group): bool;
}
