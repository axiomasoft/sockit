<?php

declare(strict_types=1);

namespace SocKit\Operations\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\CommandNamespace;

/** Explicit host policy for revealing absence when no owned receipt/action exists.
 * Called only after current trusted context/surface/admission/audience checks, outside tx.
 * Map supported command namespaces to their actual authorization action, consistently with
 * OwnerMutation::request; never guess that commandType is an action. An unknown mapping,
 * missing provider, remote failure or Unknown denies. No receipt/result/key is disclosed.
 * No default permissive policy, cache or automatic retry. Bind scoped. Stable SPI additions
 * require a separate capability. Existing receipts always authorize their stored action.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MissingReceiptPolicy
{
    public function decide(OperationHandle $handle, CommandNamespace $namespace): AuthorizationDecision;
}
