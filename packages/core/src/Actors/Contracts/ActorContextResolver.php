<?php

declare(strict_types=1);

namespace SocKit\Actors\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Support\Data\ActorRef;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ActorContextResolver
{
    /**
     * Resolve the authenticated request/process principal once per operation, outside the transaction.
     * An unknown principal must never silently become anonymous.
     *
     * @throws UnresolvedActorException
     */
    public function current(): ActorContext;

    /**
     * Resolve current host facts for worker/replay, rather than an event snapshot.
     *
     * @throws UnresolvedActorException
     */
    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext;
}
