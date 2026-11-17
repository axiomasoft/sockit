<?php

declare(strict_types=1);

namespace SocKit\Registrars\Internal;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Extensions;
use SocKit\Actors\ActorContexts;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Configuration\SockitOptions;
use SocKit\Modules\Groups\Contracts\MembershipConsent;
use SocKit\Operations\Contracts\ContentSafetyChecker;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Internal\DatabaseOperationEnvelope;
use SocKit\Surfaces\Contracts\TargetResolver;

/**
 * Resolves request/job-scoped security adapters and slot handlers on demand so domain services
 * never retain stale stateful adapters across Octane requests, cloned containers, or queue jobs.
 */
#[Internal]
final class SockitRuntime
{
    private static ?string $ephemeralCursorSecret = null;

    public function __construct(private readonly ?Container $container = null) {}

    public function actors(): ActorContexts
    {
        return $this->container()->make(ActorContexts::class);
    }

    public function authorizer(): ActionAuthorizer
    {
        return $this->container()->make(ActionAuthorizer::class);
    }

    public function audienceGate(): AudienceGate
    {
        return $this->container()->make(AudienceGate::class);
    }

    public function envelope(): OperationEnvelopeRunner
    {
        return $this->container()->make(OperationEnvelopeRunner::class);
    }

    public function surfaceLifecycleEnvelope(): DatabaseOperationEnvelope
    {
        $runner = $this->envelope();

        if ($runner instanceof DatabaseOperationEnvelope) {
            return $runner;
        }

        return $this->container()->make(DatabaseOperationEnvelope::class);
    }

    public function membershipConsent(): MembershipConsent
    {
        return $this->container()->make(MembershipConsent::class);
    }

    public function options(): SockitOptions
    {
        return $this->container()->make(SockitOptions::class);
    }

    public function cursorTtlSeconds(): int
    {
        return $this->options()->cursorTtlSeconds;
    }

    public function cursorSigningKey(): string
    {
        $container = $this->container();
        $raw = $container->bound(Repository::class)
            ? $container->make(Repository::class)->get('app.key')
            : null;

        if (is_string($raw) && $raw !== '') {
            if (str_starts_with($raw, 'base64:')) {
                $decoded = base64_decode(substr($raw, 7), true);
                if ($decoded !== false && $decoded !== '') {
                    return hash_hmac('sha256', 'sockit.feed.cursor.v1', $decoded, true);
                }
            }

            return hash_hmac('sha256', 'sockit.feed.cursor.v1', $raw, true);
        }

        // Ephemeral process-bound secret when no app.key is configured; never derived from panel/surface inputs.
        return self::$ephemeralCursorSecret ??= random_bytes(32);
    }

    /** @return list<TargetResolver> */
    public function targetResolvers(): array
    {
        $container = $this->container();
        $resolvers = [];
        $options = $container->make(SockitOptions::class);

        if ($options->targetResolver !== null) {
            $made = $container->make($options->targetResolver);
            if ($made instanceof TargetResolver) {
                $resolvers[] = $made;
            }
        }

        if ($container->bound(TargetResolver::class)) {
            $resolvers[] = $container->make(TargetResolver::class);
        }

        if ($container->bound(Extensions::class)) {
            foreach ($container->make(Extensions::class)->resolve(TargetResolver::class) as $slotResolver) {
                $resolvers[] = $slotResolver;
            }
        }

        return $resolvers;
    }

    /** @return list<ContentSafetyChecker> */
    public function safetyCheckers(): array
    {
        $container = $this->container();
        $checkers = [];

        if ($container->bound(ContentSafetyChecker::class)) {
            $checkers[] = $container->make(ContentSafetyChecker::class);
        }

        if ($container->bound(Extensions::class)) {
            foreach ($container->make(Extensions::class)->resolve(ContentSafetyChecker::class) as $slotChecker) {
                $checkers[] = $slotChecker;
            }
        }

        return $checkers;
    }

    /** @return list<OutboxConsumer> */
    public function outboxConsumers(): array
    {
        $container = $this->container();
        $consumers = [];

        if ($container->bound(Extensions::class)) {
            foreach ($container->make(Extensions::class)->resolve(OutboxConsumer::class) as $slotConsumer) {
                $consumers[] = $slotConsumer;
            }
        }

        if ($container instanceof IlluminateContainer) {
            foreach ($container->tagged(OutboxConsumer::class) as $tagged) {
                if ($tagged instanceof OutboxConsumer) {
                    $consumers[] = $tagged;
                }
            }
        }

        return $consumers;
    }

    private function container(): Container
    {
        return $this->container ?? IlluminateContainer::getInstance();
    }
}
