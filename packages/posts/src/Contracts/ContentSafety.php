<?php

declare(strict_types=1);

namespace SocKit\Posts\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Data\SafetyVerdict;

/** Bind explicitly as scoped. Assessment is read-only outside SQL transactions.
 * Allow/Hold/Reject authorize recording the respective publication state. Unknown/error denies storage.
 * Host sanctions/consent remain separate ActionAuthorizer + MutationAuthorityFence facts.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ContentSafety
{
    public function assess(OperationHandle $handle, PostCommand $post): SafetyVerdict;
}
