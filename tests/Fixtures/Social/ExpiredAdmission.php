<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Surfaces\Data\SurfaceRef;

final class ExpiredAdmission implements SurfaceAudiencePolicy
{
    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        return new AudienceVerdict(decision: VerdictDecision::Allow, reason: 'audience.allowed.platform', expiresAt: new \DateTimeImmutable('-1 second'));
    }

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        return AuthorizationDecision::deny('fixture.expired');
    }
}
