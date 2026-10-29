<?php

declare(strict_types=1);

namespace SocKit\Operations;

use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Actors\ActorContexts;
use SocKit\Attributes\Api;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Modules\Modules;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Data\DeliveryResult;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\Internal\Outbox\OutboxException;
use SocKit\Operations\Internal\Outbox\OutboxLease;
use SocKit\Operations\Internal\Outbox\OutboxStore;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Enums\SurfaceLifecycle;

/** Explicit trusted host isolation/actor handle per poll; no ambient panel and no captured consumer instances.
 * Scope is one surface incarnation. Caller enumerates authorized handles. No cross-panel atomicity.
 * Public relay is also the guarded owner lookup for these surface events. Consumers receive no body;
 * future domain resources require their own public current owner lookup before any protected output.
 */
#[Api(since: '1.0')]
final readonly class OutboxRelay
{
    public function __construct(private Application $app) {}

    /** FQCN declarations only. Inactive/unselected consumers are never resolved.
     * @return array<string, class-string<OutboxConsumer>> */
    public function consumers(OperationHandle $handle): array
    {
        $this->outside($handle);
        $this->app->make(OperationHandles::class)->assertCurrent($handle);
        $result = [];
        foreach ($this->app->make(Modules::class)->declarations() as $module => $declaration) {
            if (in_array($module, $handle->panel->modules, true)) {
                $result += $declaration['consumers'];
            }
        }
        ksort($result);

        return $result;
    }

    /** Polling is authoritative; optional afterCommit wakeups confer no delivery guarantee.
     * ProjectionOnly never changes delivery leases/ACK/inbox; consumers must isolate projection dedup.
     * Results are per attempt and never assert a remote exactly-once guarantee.
     *
     * @return list<DeliveryResult>
     */
    public function poll(OperationHandle $handle, ReplayMode $mode = ReplayMode::Delivery, int $limit = 100): array
    {
        $this->outside($handle);
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidConfigurationException(message: 'Invalid relay batch limit', detail: ['key' => 'outbox.limit']);
        }
        $consumers = $this->consumers($handle);
        $owner = $this->app->make(OwnerStorage::class)->forHandle($handle);
        $store = new OutboxStore(owner: $owner, config: $this->app->make(SocKitConfig::class)->events);
        $results = [];
        if ($mode === ReplayMode::ProjectionOnly) {
            try {
                $events = $store->projectionEvents($limit);
            } catch (\Throwable $error) {
                return [DeliveryResult::quarantine(problem: $error instanceof ProvidesProblem ? $error->problem() : ProblemCode::PayloadInvalid, reason: 'outbox.projection_identity_rejected')];
            }
            foreach ($events as $wire) {
                foreach ($consumers as $consumer) {
                    $results[] = $this->dispatch(handle: $handle, wire: $wire, consumer: $consumer, mode: $mode);
                }
            }

            return $results;
        }
        for ($i = 0; $i < $limit; $i++) {
            $lease = $store->claim();
            if ($lease === null) {
                break;
            }
            $consumer = $consumers[$lease->consumerId] ?? null;
            $result = $consumer === null
                ? DeliveryResult::quarantine(problem: ProblemCode::ProviderUnavailable, reason: 'outbox.consumer_unavailable')
                : $this->dispatch(handle: $handle, wire: $lease->encoded, consumer: $consumer, mode: $mode, lease: $lease);
            $results[] = $store->acknowledge(lease: $lease, result: $result) ? $result : DeliveryResult::unknown();
        }

        return $results;
    }

    /** @param array<string, mixed>|string $wire @param class-string<OutboxConsumer> $consumer */
    private function dispatch(OperationHandle $handle, array|string $wire, string $consumer, ReplayMode $mode, ?OutboxLease $lease = null): DeliveryResult
    {
        try {
            if (is_string($wire)) {
                try {
                    $decoded = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new InvalidPayloadException('Invalid stored event JSON');
                }
                if (! is_array($decoded)) {
                    throw new InvalidPayloadException('Expected stored event object');
                }
                $wire = [];
                foreach ($decoded as $key => $value) {
                    if (! is_string($key)) {
                        throw new InvalidPayloadException('Expected stored event keys');
                    }
                    $wire[$key] = $value;
                }
            }
            $event = EventEnvelope::fromWire($wire);
            if ($lease !== null && ($event->eventId !== $lease->eventId || $event->sourceGeneration !== $lease->generation || $event->digest->value !== $lease->digest)) {
                throw new OutboxException(ProblemCode::EventIdentityConflict);
            }
            if ($event->sourceGeneration !== $this->app->make(SocKitConfig::class)->events->sourceGeneration) {
                return DeliveryResult::quarantine(problem: ProblemCode::PanelBindingMismatch, reason: 'outbox.source_generation_stale');
            }
            if ($lease !== null && $lease->attempt > $this->app->make(SocKitConfig::class)->events->maxAttempts) {
                return DeliveryResult::quarantine(problem: ProblemCode::ProviderUnavailable, reason: 'outbox.attempts_exhausted');
            }
            $this->guard(handle: $handle, event: $event);
        } catch (\Throwable $error) {
            return DeliveryResult::quarantine(problem: $error instanceof ProvidesProblem ? $error->problem() : ProblemCode::ProviderUnavailable, reason: 'outbox.current_boundary_rejected');
        }
        try {
            $this->outside($handle);
            // Make per attempt, never register a singleton consumer with an actor snapshot.
            $instance = $this->app->make($consumer);
            if (! $instance instanceof OutboxConsumer) {
                return DeliveryResult::quarantine(problem: ProblemCode::ProviderUnavailable, reason: 'outbox.consumer_invalid');
            }

            return $instance->consume(event: $event, mode: $mode);
        } catch (\Throwable) {
            // The consumer might have committed. Retry the SAME identity, do not invent an absent outcome.
            return DeliveryResult::unknown();
        }
    }

    private function guard(OperationHandle $handle, EventEnvelope $event): void
    {
        $this->outside($handle);
        $this->app->make(OperationHandles::class)->assertCurrent($handle);
        if (! $event->surface->equals($handle->surface) || ! $event->sourceInstallation->equals($handle->actor->installation)
            || $event->sourceGeneration !== $this->app->make(SocKitConfig::class)->events->sourceGeneration || $handle->actor->actor === null) {
            throw new OutboxException(ProblemCode::PanelBindingMismatch);
        }
        $worker = $this->app->make(ActorContexts::class)->forWorker($handle->actor->actor);
        if ($worker->kind !== $handle->actor->kind || $worker->generation !== $handle->actor->generation || $worker->onBehalfOf != $handle->actor->onBehalfOf) {
            throw new OutboxException(ProblemCode::ActorContextInvalid);
        }
        $this->app->make(ActionAuthorizer::class)->decide(new ActionRequest(actor: $worker, action: 'sockit.events.deliver', surface: $event->surface))->requireAllowed();
        // Local lifecycle snapshot under a short owner lock; no host callbacks or consumer under that lock.
        $owner = $this->app->make(OwnerStorage::class)->forHandle($handle);
        $current = $owner->transaction(fn () => new SurfaceInstances($owner)->lock());
        if ($current->lifecycle === SurfaceLifecycle::Deleted) {
            throw new OutboxException(ProblemCode::ContentDeleted);
        }
        if (! $current->revision->equals($event->sourceRevision) || ! $current->privacyGeneration->equals($event->privacyRevision)) {
            throw new OutboxException(ProblemCode::RevisionConflict);
        }
        foreach ($event->payload as $resource) {
            // No generic sibling/foreign SQL lookup. P4 must add its own public owner lookup capability.
            if ($resource != $current->resource()) {
                throw new OutboxException(ProblemCode::ContentHidden);
            }
        }
        $verdict = $this->app->make(AudienceGate::class)->read(viewer: $worker, subject: new AudienceSubject(key: $event->eventId, spec: $event->audience,
            author: null, surface: $event->surface, installation: $event->sourceInstallation, surfaceArchived: $current->lifecycle === SurfaceLifecycle::Archived,
            archiveReaders: $handle->definition->archiveReaders));
        if (! $verdict->canRead() || ($verdict->expiresAt !== null && $verdict->expiresAt <= new DateTimeImmutable('now'))) {
            throw new OutboxException(ProblemCode::AudienceDenied);
        }
        if ($event->target !== null) {
            $target = $this->app->make(OperationHandles::class)->resolveTarget(handle: $handle, target: $event->target);
            if (! $target->privacyRevision->equals($event->privacyRevision) || ! $target->read->canRead() || ($target->read->expiresAt !== null && $target->read->expiresAt <= new DateTimeImmutable('now'))) {
                throw new OutboxException(ProblemCode::ContentHidden);
            }
        }
    }

    private function outside(OperationHandle $handle): void
    {
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 0) {
            throw new InvalidConfigurationException(message: 'Relay/consumer requires no active owner transaction', detail: ['key' => 'transaction']);
        }
    }
}
