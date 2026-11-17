<?php

declare(strict_types=1);

namespace SocKit\Registrars\Internal;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Extensions;
use SocKit\Actors\ActorContexts;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Configuration\SockitOptions;
use SocKit\Operations\Contracts\ContentSafetyChecker;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Surfaces\Contracts\TargetResolver;

/**
 * Resolves request/job-scoped security adapters and slot handlers on demand so domain services
 * never retain stale stateful adapters across Octane requests, queue jobs, or container flushes.
 */
#[Internal]
final readonly class SockitRuntime
{
    public function __construct(private Container $container) {}

    public function actors(): ActorContexts
    {
        return $this->container->make(ActorContexts::class);
    }

    public function authorizer(): ActionAuthorizer
    {
        return $this->container->make(ActionAuthorizer::class);
    }

    public function audienceGate(): AudienceGate
    {
        return $this->container->make(AudienceGate::class);
    }

    public function envelope(): OperationEnvelopeRunner
    {
        return $this->container->make(OperationEnvelopeRunner::class);
    }

    /** @return list<TargetResolver> */
    public function targetResolvers(): array
    {
        $resolvers = [];
        $options = $this->container->make(SockitOptions::class);

        if ($options->targetResolver !== null) {
            $made = $this->container->make($options->targetResolver);
            if ($made instanceof TargetResolver) {
                $resolvers[] = $made;
            }
        }

        if ($this->container->bound(TargetResolver::class)) {
            $resolvers[] = $this->container->make(TargetResolver::class);
        }

        if ($this->container->bound(Extensions::class)) {
            foreach ($this->container->make(Extensions::class)->resolve(TargetResolver::class) as $slotResolver) {
                $resolvers[] = $slotResolver;
            }
        }

        return $resolvers;
    }

    /** @return list<ContentSafetyChecker> */
    public function safetyCheckers(): array
    {
        $checkers = [];

        if ($this->container->bound(ContentSafetyChecker::class)) {
            $checkers[] = $this->container->make(ContentSafetyChecker::class);
        }

        if ($this->container->bound(Extensions::class)) {
            foreach ($this->container->make(Extensions::class)->resolve(ContentSafetyChecker::class) as $slotChecker) {
                $checkers[] = $slotChecker;
            }
        }

        return $checkers;
    }

    /** @return list<OutboxConsumer> */
    public function outboxConsumers(): array
    {
        $consumers = [];

        if ($this->container->bound(Extensions::class)) {
            foreach ($this->container->make(Extensions::class)->resolve(OutboxConsumer::class) as $slotConsumer) {
                $consumers[] = $slotConsumer;
            }
        }

        if ($this->container instanceof IlluminateContainer) {
            foreach ($this->container->tagged(OutboxConsumer::class) as $tagged) {
                if ($tagged instanceof OutboxConsumer) {
                    $consumers[] = $tagged;
                }
            }
        }

        return $consumers;
    }
}
