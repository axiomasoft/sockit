<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use SocKit\Actors\Data\ActorContext;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Surfaces\Data\SurfaceRef;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SurfaceAudiencePolicy
{
    /** Validate trusted installation, registered panel, target binding/incarnation and current host admission.
     * Unknown boundaries must deny. Actor equality or authorship never proves admission.
     */
    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict;

    /** Current set containment, never a comparison of scope ranks. Only explicit Allow passes. */
    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision;
}
