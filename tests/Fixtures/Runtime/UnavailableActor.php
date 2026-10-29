<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Support\Data\ActorRef;

final class UnavailableActor implements ActorContextResolver
{
    public function current(): ActorContext
    {
        throw new UnresolvedActorException('actor removed');
    }

    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        throw new UnresolvedActorException('actor removed');
    }
}
