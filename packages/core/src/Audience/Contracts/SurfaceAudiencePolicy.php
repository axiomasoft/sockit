<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Host SPI enforcing current surface admission and proposed audience set containment (D16).
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SurfaceAudiencePolicy
{
    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict;

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision;
}
