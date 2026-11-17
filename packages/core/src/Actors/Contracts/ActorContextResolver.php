<?php

declare(strict_types=1);

namespace SocKit\Actors\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Support\Data\ActorRef;

/**
 * Host SPI resolving the current authenticated principal and re-resolving actors in workers/replay.
 *
 * Called once per operation outside SQL transactions. Must fail closed (throw) when absent; never invent guests.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ActorContextResolver
{
    /** @throws UnresolvedActorException */
    public function current(): ActorContext;

    /** @throws UnresolvedActorException */
    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext;
}
