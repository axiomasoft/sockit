<?php

declare(strict_types=1);

namespace SocKit\Actors;

use Packman\Attributes\Api;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Support\Data\ActorRef;

#[Api(since: '1.0')]
final readonly class ActorContexts
{
    public function __construct(private ActorContextResolver $resolver, private TenantResolver $tenants) {}

    public function current(): ActorContext
    {
        return $this->checked($this->resolver->current());
    }

    /** The actor reference is trusted worker metadata, never a payload-supplied author. */
    public function forWorker(ActorRef $actor): ActorContext
    {
        $context = $this->checked($this->resolver->reResolve(actor: $actor, provenance: ContextProvenance::Worker));

        if (! $context->sameActor($actor) || $context->provenance !== ContextProvenance::Worker) {
            throw new ActorContextMismatchException('Worker context does not match the requested principal or provenance');
        }

        return $context;
    }

    private function checked(ActorContext $context): ActorContext
    {
        if (! $context->installation->equals($this->tenants->current())) {
            throw new ActorContextMismatchException('Actor context does not match the current installation');
        }

        return $context;
    }
}
