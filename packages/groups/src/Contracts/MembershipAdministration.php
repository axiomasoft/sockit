<?php

declare(strict_types=1);

namespace SocKit\Groups\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Operations\Data\OperationHandle;

/** Verify the executor can perform this operation for this exact group, subject and requested role.
 * Capture outside the transaction. Recheck locally and hold the authority fence until commit.
 * Group roles are domain data and never grant administrative host permissions.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MembershipAdministration
{
    public function capture(OperationHandle $handle, MembershipCommand $command): string;

    public function recheck(OperationHandle $handle, MembershipCommand $command, string $generation): AuthorizationDecision;
}
