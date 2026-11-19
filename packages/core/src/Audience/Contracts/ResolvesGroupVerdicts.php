<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;

/** Provides local checkpoints for affirmative and confirmed negative membership decisions. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ResolvesGroupVerdicts extends GroupMembershipResolver
{
    public function decideMembership(ActorContext $actor, GroupRef $group): AudienceVerdict;
}
