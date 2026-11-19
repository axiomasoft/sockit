<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Envelope;

use Packman\Attributes\Internal;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Support\Data\ActorRef;

/** Per-call immutable adapter; current() never consults a worker's ambient principal. */
#[Internal]
final readonly class WorkerActor implements ActorContextResolver
{
    public function __construct(private ActorContexts $actors, private ActorRef $actor) {}

    public function current(): ActorContext
    {
        return $this->actors->forWorker($this->actor);
    }

    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        if (! $this->actor->equals($actor) || $provenance !== ContextProvenance::Worker) {
            throw new ActorContextMismatchException('Worker principal substitution');
        }

        return $this->current();
    }
}
