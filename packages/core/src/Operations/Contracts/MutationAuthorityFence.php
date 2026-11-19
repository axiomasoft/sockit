<?php

declare(strict_types=1);

namespace SocKit\Operations\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\OperationHandle;

/** Mandatory host consistency boundary for guarded writes; bind explicitly as scoped.
 * capture runs outside tx BEFORE current action/admission/audience/containment guards.
 * It returns a nonempty generation (<=128 bytes) covering those facts for the complete
 * handle/request identity. No permission is conferred by a token alone.
 * recheck runs locally on the exact owner connection AFTER all owner locks, before persist.
 * It must prove those same facts still allow this write and hold their fence until commit.
 * A revoke writer must participate in this same local fence/lock order. No network,
 * remote resolvers, ambient actor lookup or cross-connection writes under owner locks.
 * Missing/stale/unprovable facts => Unknown; revoked => Deny. Errors fail closed.
 * No automatic retries/cache/batch; caller retries the SAME key/digest after fresh resolution.
 * Remote authority without a provable local fence cannot enable writes. Stable SPI additions
 * require a separate capability. The implementation owns its host SQL, never kernel tables.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MutationAuthorityFence
{
    public function capture(OperationHandle $handle, MutationRequest $request): string;

    public function recheck(OperationHandle $handle, MutationRequest $request, string $generation): AuthorizationDecision;
}
