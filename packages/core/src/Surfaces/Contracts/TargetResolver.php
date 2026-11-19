<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;

/** Host adapter for L0 Article/Product and future P4 module capabilities.
 * Called through OperationHandles at every protected entry/read/replay/lookup, outside owner transactions.
 * May perform bounded remote IO with host timeouts; no automatic retries/batch. Throwable/mismatch fails closed.
 * Return exact refs and trusted installation, current read/write visibility, lifecycle, privacy revision and
 * nonempty generation. Missing/revoked/unknown never allow. No body/ORM or actor from payload.
 * No cached authorization across entries. Remote reads are sampled; cross-DB atomic revocation is not promised.
 * Writes additionally require TargetWriteFence. This stable interface is extended only with separate capabilities.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TargetResolver
{
    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution;
}
