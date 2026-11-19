<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Surfaces\Data\TargetResolution;

/** Optional capability on the selected TargetResolver instance, never a separate permissive fallback.
 * Called once under owner locks/transaction after receipt -> surface -> target lock order (P2.7).
 * Local SQL only on the exact owner connection from the immutable handle. Adapter owns host table queries/locks.
 * Recheck incarnation, lifecycle, visibility, privacyRevision and generation; hold a fence until owner commit.
 * No network, remote authority/actor resolution, timeout retry or cached allow. Throwable/unprovable => Unknown.
 * Remote-only targets cannot write in this profile. Roll back before re-resolving outside the transaction.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TargetWriteFence
{
    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision;
}
