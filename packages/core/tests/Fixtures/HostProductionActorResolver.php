<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use Override;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;

final class HostProductionActorResolver implements ActorContextResolver
{
    private ActorContext $context;

    public function __construct()
    {
        $this->context = ActorContext::person(
            actor: new ActorRef('user', 'alice'),
            installation: new InstallationRef('default'),
        );
    }

    public function useContext(ActorContext $context): void
    {
        $this->context = $context;
    }

    #[Override]
    public function current(): ActorContext
    {
        return $this->context;
    }

    #[Override]
    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        return ActorContext::person($actor, $this->context->installation, provenance: $provenance);
    }
}
