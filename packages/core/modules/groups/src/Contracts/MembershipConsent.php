<?php

declare(strict_types=1);

namespace SocKit\Groups\Contracts;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Groups\Data\ConsentDecision;
use SocKit\Groups\Data\MembershipActivation;
use SocKit\Operations\Data\OperationHandle;

/** Explicit host binding required, including deliberate allow when consent is unnecessary.
 * assess resolves the prospective SUBJECT's identity, installation and consent outside tx.
 * recheck is LOCAL ONLY on the exact owner connection after group/member locks and before persist.
 * It must prove captured consent remains current and hold its fence until commit; the host
 * revoker participates in the same fence/lock order. Unknown/stale/error denies activation.
 * No remote work under locks, host decision copies, age policy or cross-DB atomicity promise.
 * Active reads remain constrained by current host admission, including consent revocation.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MembershipConsent
{
    public function assess(OperationHandle $handle, MembershipActivation $activation): ConsentDecision;

    public function recheck(OperationHandle $handle, MembershipActivation $activation, ConsentDecision $captured): AuthorizationDecision;
}
