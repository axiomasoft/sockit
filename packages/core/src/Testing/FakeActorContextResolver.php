<?php

declare(strict_types=1);

namespace SocKit\Testing;

use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Attributes\Api;
use SocKit\Support\Data\ActorRef;

#[Api(since: '1.0')]
final class FakeActorContextResolver implements ActorContextResolver
{
    private ?ActorContext $context = null;

    /** @var array<string, ActorContext> */
    private array $registered = [];

    public function actingAs(ActorContext $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function register(ActorContext $context): self
    {
        if ($context->actor === null) {
            throw new UnresolvedActorException('Cannot register an anonymous context for actor re-resolution');
        }

        $this->registered[$context->actor->toWire()] = $context;

        return $this;
    }

    public function current(): ActorContext
    {
        return $this->context ?? throw new UnresolvedActorException('No current principal is configured');
    }

    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        $context = $this->registered[$actor->toWire()] ?? throw new UnresolvedActorException('Actor is not registered');

        return new ActorContext(
            actor: $context->actor,
            kind: $context->kind,
            installation: $context->installation,
            ageBand: $context->ageBand,
            provenance: $provenance,
            onBehalfOf: $context->onBehalfOf,
            generation: $context->generation,
        );
    }
}
