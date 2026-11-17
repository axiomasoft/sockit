<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;

/**
 * Mutable test double for {@see ActorContextResolver}.
 */
#[Api(since: '1.0')]
final class FakeActorContextResolver implements ActorContextResolver
{
    private ?ActorContext $currentContext;

    private bool $failUnresolved = false;

    public function __construct(?ActorContext $initial = null)
    {
        $this->currentContext = $initial ?? ActorContext::person(
            new ActorRef('user', '1'),
            new InstallationRef('inst-1'),
        );
    }

    public function actAs(ActorContext $context): self
    {
        $this->failUnresolved = false;
        $this->currentContext = $context;

        return $this;
    }

    public function actAsPerson(
        string $id,
        string $type = 'user',
        string $installation = 'inst-1',
        AgeBand $ageBand = AgeBand::Adult,
        ContextProvenance $provenance = ContextProvenance::Http,
    ): self {
        return $this->actAs(ActorContext::person(
            actor: new ActorRef($type, $id),
            installation: new InstallationRef($installation),
            ageBand: $ageBand,
            provenance: $provenance,
        ));
    }

    public function anonymous(string $installation = 'inst-1'): self
    {
        return $this->actAs(ActorContext::anonymous(new InstallationRef($installation)));
    }

    public function unresolved(): self
    {
        $this->failUnresolved = true;
        $this->currentContext = null;

        return $this;
    }

    #[Override]
    public function current(): ActorContext
    {
        if ($this->failUnresolved || ! $this->currentContext instanceof ActorContext) {
            throw UnresolvedActorException::unauthenticated();
        }

        return $this->currentContext;
    }

    #[Override]
    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        $installation = $this->currentContext instanceof ActorContext
            ? $this->currentContext->installation
            : new InstallationRef('inst-1');

        return ActorContext::person($actor, $installation, provenance: $provenance);
    }
}
