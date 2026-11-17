<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use Override;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Modules\Groups\Contracts\MembershipConsent;
use SocKit\Modules\Groups\Data\ConsentDecision;
use SocKit\Modules\Groups\Data\MembershipActivation;

final readonly class HostProductionMembershipConsent implements MembershipConsent
{
    #[Override]
    public function assess(MembershipActivation $activation): ConsentDecision
    {
        return new ConsentDecision(
            decision: AuthorizationDecision::allow('host.consent_granted'),
            generation: 'prod-consent-1',
        );
    }

    #[Override]
    public function recheck(MembershipActivation $activation, ConsentDecision $decision): AuthorizationDecision
    {
        return $decision->decision;
    }
}
