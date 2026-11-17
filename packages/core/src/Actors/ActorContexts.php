<?php

declare(strict_types=1);

namespace SocKit\Actors;

use Packman\Attributes\Api;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Support\Data\ActorRef;

/**
 * Single authoritative entry point for resolving and installation-verifying ActorContext in SocKit.
 */
#[Api(since: '1.0')]
final readonly class ActorContexts
{
    public function __construct(
        private ActorContextResolver $resolver,
        private TenantResolver $tenants,
    ) {}

    /**
     * @throws UnresolvedActorException
     * @throws ActorContextMismatchException
     */
    public function current(): ActorContext
    {
        $expected = $this->tenants->current();
        $context = $this->resolver->current();

        if (! $context->installation->equals($expected)) {
            throw ActorContextMismatchException::installationMismatch($expected->id, $context->installation->id);
        }

        return $context;
    }

    /**
     * @throws UnresolvedActorException
     * @throws ActorContextMismatchException
     */
    public function forWorker(ActorRef $actor, ContextProvenance $provenance = ContextProvenance::Worker): ActorContext
    {
        $expected = $this->tenants->current();
        $context = $this->resolver->reResolve($actor, $provenance);

        if (! $context->installation->equals($expected)) {
            throw ActorContextMismatchException::installationMismatch($expected->id, $context->installation->id);
        }

        return $context;
    }
}
