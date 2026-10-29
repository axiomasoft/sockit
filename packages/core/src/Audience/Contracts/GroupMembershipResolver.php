<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use SocKit\Actors\Data\ActorContext;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Audience\Data\GroupRef;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface GroupMembershipResolver
{
    public function isMember(ActorContext $actor, GroupRef $group): bool;
}
