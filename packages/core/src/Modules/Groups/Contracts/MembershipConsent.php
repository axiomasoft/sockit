<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Modules\Groups\Data\ConsentDecision;
use SocKit\Modules\Groups\Data\MembershipActivation;

/**
 * Host consent SPI invoked before activating group membership and re-verified locally under lock.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MembershipConsent
{
    public function assess(MembershipActivation $activation): ConsentDecision;

    public function recheck(MembershipActivation $activation, ConsentDecision $decision): AuthorizationDecision;
}
