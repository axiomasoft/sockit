<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Envelope;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Enums\AuthorizationStatus;
use SocKit\Configuration\SocKitConfig;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Contracts\MissingReceiptPolicy;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Data\MutationEffect;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\MutationSnapshot;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Data\ReceiptSnapshot;
use SocKit\Operations\Internal\Outbox\OutboxStore;
use SocKit\Operations\Internal\Receipts\ReceiptException;
use SocKit\Operations\Internal\Receipts\ReceiptLedger;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\OutboxRelay;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceInstance;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\SurfaceLifecycle;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Fixed core protocol. No user stages can skip claim/guards/CAS/outbox or commit an outer transaction. */
final readonly class Envelope
{
    public function __construct(private Application $app) {}

    public function execute(OperationHandle $handle, CommandEnvelope $command, OwnerMutation $mutation): OperationResult
    {
        $recordable = false;
        $context = null;
        $payload = [];
        $refreshAuthority = false;
        try {
            $this->outside($handle);
            $this->handles()->assertCurrent(handle: $handle, namespace: $command->namespace);
            $request = $mutation->request($handle);
            $payload = $this->payload(handle: $handle, mutation: $mutation, request: $request, expected: $command->expectedRevision);
            if ($command->namespace->commandType !== $mutation->commandType()
                || ! PayloadDigest::of(commandType: $mutation->commandType(), payload: $payload)->equals($command->digest)
                || $request->intent === 'read'
                || (! $mutation instanceof SurfaceMutation && (str_starts_with($mutation->commandType(), 'sockit.') || $request->target === null))) {
                $this->reject(ProblemCode::PayloadInvalid);
            }
            if ($mutation instanceof SurfaceMutation && (($mutation->action === 'create') !== ($command->expectedRevision === null))) {
                $this->reject(ProblemCode::PayloadInvalid);
            }
            $owner = $this->app->make(OwnerStorage::class)->forHandle(handle: $handle, namespace: $command->namespace);
            $surfaces = new SurfaceInstances($owner);
            $sample = $surfaces->current();
            $target = $this->target(handle: $handle, request: $request);
            $context = $this->context(handle: $handle, request: $request, command: $command, target: $target);
            $recordable = true; // Trusted actor/namespace/subject/digest known; rejected receipts carry no resource.
            $this->safety($request->safety);
            $ledger = new ReceiptLedger($owner);
            // Try a committed winner through fresh READ guards; CAS/write permission is never reapplied to replay.
            if ($ledger->context(namespace: $command->namespace, key: $command->key) !== null) {
                return $this->replay(handle: $handle, command: $command, context: $context);
            }
            $create = $mutation instanceof SurfaceMutation && $mutation->action === 'create';
            $maintenance = $mutation instanceof SurfaceMutation && in_array($mutation->action, ['resume', 'delete'], true);
            $this->surfaceState(handle: $handle, current: $sample, create: $create, write: true, maintenance: $maintenance);
            if (! $this->app->bound(MutationAuthorityFence::class)) {
                $this->reject(ProblemCode::ProviderUnavailable);
            }
            $authority = $this->app->make(MutationAuthorityFence::class);
            $generation = $authority->capture(handle: $handle, request: $request);
            if ($generation === '' || strlen($generation) > 128 || preg_match('/[\x00-\x1f\x7f]/', $generation)) {
                $this->reject(ProblemCode::AuthorizationUnknown);
            }
            $this->guards(handle: $handle, request: $request, current: $maintenance ? null : $sample, write: true, target: $target);
            $consumers = array_keys($this->app->make(OutboxRelay::class)->consumers($handle));
            $effect = new PersistedEffect;
            $snapshot = $ledger->transaction(envelope: $command, commandType: $mutation->commandType(), payload: $payload, guardContext: $context,
                write: function (string $id) use ($handle, $command, $mutation, $request, $sample, $target, $surfaces, $create, $maintenance, $effect, $authority, $generation, &$refreshAuthority): OperationResult {
                    $locked = $create ? $surfaces->current() : $surfaces->lock();
                    $this->surfaceState(handle: $handle, current: $locked, create: $create, write: true, maintenance: $maintenance);
                    if ($sample?->revision != $locked?->revision || $sample?->privacyGeneration != $locked?->privacyGeneration || $sample?->sourceGeneration != $locked?->sourceGeneration) {
                        $this->reject(ProblemCode::AuthorizationUnknown);
                    }
                    if ($target !== null) {
                        $this->handles()->recheckTarget(handle: $handle, resolved: $target)->requireAllowed();
                    }
                    $before = $mutation->lock($handle);
                    $this->snapshot(handle: $handle, request: $request, snapshot: $before, expected: $command->expectedRevision, maintenance: $maintenance);
                    // INSERT can become the winner AFTER waiting for a rolled-back holder.
                    // All other owner locks can wait too. Prove current authority locally,
                    // after every such wait and BEFORE any owner state/revision persistence.
                    $decision = $authority->recheck(handle: $handle, request: $request, generation: $generation);
                    $refreshAuthority = ! $decision->isAllowed();
                    $decision->requireAllowed();
                    $effect->value = $mutation->persist(handle: $handle, snapshot: $before);
                    $this->effect(handle: $handle, snapshot: $before, effect: $effect->value);

                    return OperationResult::confirmed(receiptId: $id, resource: $effect->value->resource, revision: $effect->value->newRevision);
                },
                afterRecord: function (ReceiptSnapshot $receipt) use ($effect, $handle, $request, $target, $surfaces, $owner, $consumers): void {
                    if ($effect->value === null) {
                        $this->reject(ProblemCode::PayloadInvalid);
                    }
                    $current = $surfaces->lock();
                    $store = new OutboxStore(owner: $owner, config: $this->app->make(SocKitConfig::class)->events);
                    foreach ($effect->value->events as $proposal) {
                        foreach ($proposal->refs as $ref) {
                            if ($ref != $effect->value->resource && $ref != $current->resource()) {
                                $this->reject(ProblemCode::PayloadInvalid);
                            }
                        }
                        $store->append(event: new EventEnvelope(eventId: Ids::uuid7(), sourceInstallation: $handle->actor->installation, panel: $handle->surface->panel,
                            sourceGeneration: $this->app->make(SocKitConfig::class)->events->sourceGeneration, surface: $handle->surface,
                            sourceRevision: $receipt->revision ?? throw new EnvelopeException(ProblemCode::PayloadInvalid), privacyRevision: $target?->privacyRevision ?: $current->privacyGeneration,
                            eventType: $proposal->type, occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')), payload: $proposal->refs,
                            audience: $request->proposedAudience ?? $request->subject->spec, target: $request->target), consumers: $consumers);
                    }
                });
            // A unique-conflict wait may have outlived authority. Re-resolve only AFTER our tx ends.
            if ($effect->value !== null) {
                // Acknowledge this command's committed owner effect, including quarantine states.
                // This is a storage acknowledgement, never a grant to read its content. Later
                // replay/lookup must additionally resolve the resource's current owner visibility.
                if ($mutation instanceof SurfaceMutation && $mutation->action === 'delete') {
                    $this->guards(handle: $handle, request: $request, current: $surfaces->current(), write: false, target: null);
                } else {
                    $this->replayGuards(handle: $handle, request: $request, owner: $owner, wire: $this->decode($context));
                }

                return new OperationResult(outcome: $snapshot->outcome, receiptId: $snapshot->id, resource: $snapshot->resource, revision: $snapshot->revision, problem: $snapshot->problem);
            }

            return $this->replay(handle: $handle, command: $command, context: $context);
        } catch (\Throwable $error) {
            $code = $this->problem($error);
            if ($refreshAuthority) {
                // The failed claim has rolled back. Resolve current facts outside tx, with
                // the SAME key/digest; never silently retry persistence after an Unknown.
                try {
                    $this->outside($handle);
                    $this->handles()->assertCurrent(handle: $handle, namespace: $command->namespace);
                    $fresh = $mutation->request($handle);
                    if (! PayloadDigest::of(commandType: $mutation->commandType(), payload: $this->payload(handle: $handle, mutation: $mutation, request: $fresh, expected: $command->expectedRevision))->equals($command->digest)) {
                        $this->reject(ProblemCode::PayloadInvalid);
                    }
                    $current = new SurfaceInstances($this->app->make(OwnerStorage::class)->forHandle($handle))->current();
                    $maintenance = $mutation instanceof SurfaceMutation && in_array($mutation->action, ['resume', 'delete'], true);
                    $this->surfaceState(handle: $handle, current: $current, create: $mutation instanceof SurfaceMutation && $mutation->action === 'create', write: true, maintenance: $maintenance);
                    $this->guards(handle: $handle, request: $fresh, current: $maintenance ? null : $current, write: true, target: $this->target(handle: $handle, request: $fresh));
                    $this->safety($fresh->safety);
                } catch (\Throwable $freshError) {
                    $code = $this->problem($freshError);
                }
            }
            if ($recordable && $context !== null && $code->spec()->receiptRecorded) {
                try {
                    $owner = $this->app->make(OwnerStorage::class)->forHandle(handle: $handle, namespace: $command->namespace);
                    $receipt = new ReceiptLedger($owner)->transaction(envelope: $command, commandType: $mutation->commandType(), payload: $payload, guardContext: $context,
                        write: static fn (string $id): OperationResult => OperationResult::rejected(problem: $code, receiptId: $id));
                    if ($receipt->problem === $code) {
                        return OperationResult::rejected(problem: $code, receiptId: $receipt->id);
                    }
                } catch (\Throwable) { /* Never overwrite/expose a winner or turn failed recording into success. */
                }
            }

            return OperationResult::rejected($code);
        }
    }

    public function lookup(OperationHandle $handle, CommandNamespace $namespace, CommandKey $key): ?OperationResult
    {
        try {
            $this->outside($handle);
            $this->handles()->assertCurrent(handle: $handle, namespace: $namespace);
            // Baseline trusted surface access protects even receipt metadata/absence.
            $owner = $this->app->make(OwnerStorage::class)->forHandle(handle: $handle, namespace: $namespace);
            $current = new SurfaceInstances($owner)->current();
            $this->surfaceState(handle: $handle, current: $current, create: false, write: false);
            $subject = new AudienceSubject(key: 'sockit.lookup', spec: AudienceSpec::direct($handle->actor->actor ?? throw new EnvelopeException(ProblemCode::AuthenticationRequired)), author: null, surface: $handle->surface, installation: $handle->actor->installation,
                surfaceArchived: $current?->lifecycle === SurfaceLifecycle::Archived, archiveReaders: $handle->definition->archiveReaders);
            $this->verdict(verdict: $this->app->make(AudienceGate::class)->read(viewer: $handle->actor, subject: $subject), write: false);
            try {
                $context = new ReceiptLedger($owner)->context(namespace: $namespace, key: $key);
            } catch (ReceiptException $identityError) {
                if ($identityError->problem() !== ProblemCode::ActorContextInvalid) {
                    throw $identityError;
                }
                $context = null; // Same policy/answer as absence, never another actor's stored action.
            }
            if ($context === null) {
                if (in_array($namespace->commandType, ['sockit.surfaces.create', 'sockit.surfaces.archive', 'sockit.surfaces.resume', 'sockit.surfaces.delete'], true)) {
                    $this->app->make(ActionAuthorizer::class)->decide(new ActionRequest(actor: $handle->actor, action: $namespace->commandType, surface: $handle->surface))->requireAllowed();
                } else {
                    if (! $this->app->bound(MissingReceiptPolicy::class)) {
                        $this->reject(ProblemCode::ProviderUnavailable);
                    }
                    $this->app->make(MissingReceiptPolicy::class)->decide(handle: $handle, namespace: $namespace)->requireAllowed();
                }

                return null;
            }
            $wire = $this->decode($context);
            if (! isset($wire['digest']) || ! is_string($wire['digest'])) {
                $this->reject(ProblemCode::ProviderUnavailable);
            }

            return $this->replay(handle: $handle, command: new CommandEnvelope(key: $key, namespace: $namespace, digest: new PayloadDigest($wire['digest'])), context: $context);
        } catch (\Throwable $error) {
            return OperationResult::rejected($this->problem($error));
        }
    }

    public function digest(OperationHandle $handle, OwnerMutation $mutation, ?Revision $expectedRevision): PayloadDigest
    {
        return PayloadDigest::of(commandType: $mutation->commandType(), payload: $this->payload(handle: $handle, mutation: $mutation, request: $mutation->request($handle), expected: $expectedRevision));
    }

    public function lifecycle(OperationHandle $handle, string $action, CommandEnvelope $command): OperationResult
    {
        try {
            $this->outside($handle);
            $owner = $this->app->make(OwnerStorage::class)->forHandle($handle);

            return $this->execute(handle: $handle, command: $command, mutation: new SurfaceMutation(owner: $owner, action: $action));
        } catch (\Throwable $error) {
            return OperationResult::rejected($this->problem($error));
        }
    }

    public function lifecycleDigest(OperationHandle $handle, string $action, ?Revision $expectedRevision): PayloadDigest
    {
        $this->outside($handle);
        $owner = $this->app->make(OwnerStorage::class)->forHandle($handle);

        return $this->digest(handle: $handle, mutation: new SurfaceMutation(owner: $owner, action: $action), expectedRevision: $expectedRevision);
    }

    public function surface(OperationHandle $handle): SurfaceInstance
    {
        $this->outside($handle);
        $this->handles()->assertCurrent($handle);
        $owner = $this->app->make(OwnerStorage::class)->forHandle($handle);
        $current = new SurfaceInstances($owner)->current();
        $this->surfaceState(handle: $handle, current: $current, create: false, write: false);
        $spec = AudienceSpec::direct($handle->actor->actor ?? throw new EnvelopeException(ProblemCode::AuthenticationRequired));
        $request = new MutationRequest(action: 'sockit.surfaces.get', subject: new AudienceSubject(key: 'sockit.surface', spec: $spec, author: null, surface: $handle->surface, installation: $handle->actor->installation),
            intent: 'read', safety: AuthorizationDecision::allow('kernel.lifecycle'), generation: 'kernel.lifecycle');
        $this->guards(handle: $handle, request: $request, current: $current, write: false, target: null);

        return $current ?? throw new EnvelopeException(ProblemCode::SurfaceNotFound);
    }

    /** @return array<string, mixed> */
    private function payload(OperationHandle $handle, OwnerMutation $mutation, MutationRequest $request, ?Revision $expected): array
    {
        if (! $request->subject->surface->equals($handle->surface) || ! $request->subject->installation->equals($handle->actor->installation)) {
            $this->reject(ProblemCode::PayloadInvalid);
        }
        $bound = ['surface' => $handle->surface->toWire(), 'target' => $request->target?->toWire(), 'audience' => $request->proposedAudience?->toWire(), 'expected_revision' => $expected?->value];
        $data = $mutation->payload();
        foreach (['author', 'actor', 'installation'] as $forbidden) {
            if (array_key_exists($forbidden, $data)) {
                $this->reject(ProblemCode::PayloadInvalid);
            }
        }
        foreach ($bound as $key => $value) {
            if (array_key_exists($key, $data) && $data[$key] !== $value) {
                $this->reject(ProblemCode::PayloadInvalid);
            }
            unset($data[$key]);
        }

        return [...$bound, 'subject_audience' => $request->subject->spec->toWire(), 'action' => $request->action, 'intent' => $request->intent, 'data' => $data];
    }

    private function target(OperationHandle $handle, MutationRequest $request): ?TargetResolution
    {
        if ($request->target === null) {
            return null;
        }
        $target = $this->handles()->resolveTarget(handle: $handle, target: $request->target);
        if (! $target->read->canRead()) {
            $this->reject(ProblemCode::ContentHidden);
        }

        return $target;
    }

    private function guards(OperationHandle $handle, MutationRequest $request, ?SurfaceInstance $current, bool $write, ?TargetResolution $target): void
    {
        $this->handles()->assertCurrent($handle);
        $this->app->make(ActionAuthorizer::class)->decide(new ActionRequest(actor: $handle->actor, action: $request->action, surface: $handle->surface))->requireAllowed();
        $subject = new AudienceSubject(key: $request->subject->key, spec: $request->subject->spec, author: null, surface: $handle->surface,
            installation: $handle->actor->installation, surfaceArchived: $current?->lifecycle === SurfaceLifecycle::Archived, archiveReaders: $handle->definition->archiveReaders);
        $gate = $this->app->make(AudienceGate::class);
        $verdict = $gate->read(viewer: $handle->actor, subject: $subject);
        $this->verdict(verdict: $verdict, write: $write);
        if ($request->proposedAudience !== null) {
            $this->app->make(SurfaceAudiencePolicy::class)->contains(actor: $handle->actor, surface: $handle->surface, proposed: $request->proposedAudience)->requireAllowed();
            if ($write) {
                $publish = new AudienceSubject(key: $subject->key, spec: $request->proposedAudience, author: null, surface: $handle->surface, installation: $handle->actor->installation, surfaceArchived: $subject->surfaceArchived);
                $this->verdict(verdict: $gate->publish(author: $handle->actor, subject: $publish, floor: $handle->definition->effectiveFloor), write: true);
            }
        }
        if ($target !== null) {
            $this->verdict(verdict: $write ? $target->write : $target->read, write: $write);
        }
    }

    private function verdict(AudienceVerdict $verdict, bool $write): void
    {
        if ($verdict->expiresAt !== null && $verdict->expiresAt <= new DateTimeImmutable('now')) {
            $this->reject(ProblemCode::AuthorizationUnknown);
        }
        if (! $verdict->canRead() || ($write && ! $verdict->canWrite())) {
            $this->reject(in_array($verdict->reason, ['audience.unknown', 'audience.resolver_error', 'audience.denied.boundary_unproven'], true) ? ProblemCode::AuthorizationUnknown : ProblemCode::AudienceDenied);
        }
    }

    private function surfaceState(OperationHandle $handle, ?SurfaceInstance $current, bool $create, bool $write, bool $maintenance = false): void
    {
        if ($create) {
            if ($current !== null) {
                $this->reject($current->lifecycle === SurfaceLifecycle::Deleted ? ProblemCode::ContentDeleted : ProblemCode::RevisionConflict);
            }

            return;
        }
        if ($current === null) {
            $this->reject(ProblemCode::SurfaceNotFound);
        }
        if (! $current->ref->equals($handle->surface)) {
            $this->reject(ProblemCode::SurfaceIncarnationMismatch);
        }
        if ($current->lifecycle === SurfaceLifecycle::Deleted) {
            $this->reject(ProblemCode::ContentDeleted);
        }
        if ($write && ! $maintenance && $current->lifecycle === SurfaceLifecycle::Archived) {
            $this->reject(ProblemCode::SurfaceArchived);
        }
    }

    private function snapshot(OperationHandle $handle, MutationRequest $request, MutationSnapshot $snapshot, ?Revision $expected, bool $maintenance): void
    {
        $this->safety($snapshot->safety);
        if ($snapshot->generation !== $request->generation) {
            $this->reject(ProblemCode::AuthorizationUnknown);
        }
        if ($snapshot->resource !== null && ! $snapshot->resource->panel->equals($handle->surface->panel)) {
            $this->reject(ProblemCode::PayloadInvalid);
        }
        if ($snapshot->lifecycle !== TargetLifecycle::Live && ! ($maintenance && $snapshot->lifecycle === TargetLifecycle::ReadOnly)) {
            $this->reject(ProblemCode::ContentHidden);
        }
        if (($snapshot->revision === null) !== ($expected === null)) {
            $this->reject(ProblemCode::PayloadInvalid);
        }
        if ($snapshot->revision !== null && ! $snapshot->revision->equals($expected ?? throw new EnvelopeException(ProblemCode::PayloadInvalid))) {
            $this->reject(ProblemCode::RevisionConflict);
        }
    }

    private function effect(OperationHandle $handle, MutationSnapshot $snapshot, MutationEffect $effect): void
    {
        if ($effect->affectedRows !== 1 || ! $effect->resource->panel->equals($handle->surface->panel) || ($snapshot->resource !== null && $snapshot->resource != $effect->resource)
            || ! $effect->newRevision->equals($snapshot->revision?->next() ?? new Revision(1)) || $effect->events === [] || count($effect->events) > 100) {
            $this->reject(ProblemCode::PayloadInvalid);
        }
    }

    private function safety(AuthorizationDecision $safety): void
    {
        if (! $safety->isAllowed()) {
            $this->reject($safety->status === AuthorizationStatus::Unknown ? ProblemCode::ProviderUnavailable : ProblemCode::ContentHeld);
        }
    }

    private function context(OperationHandle $handle, MutationRequest $request, CommandEnvelope $command, ?TargetResolution $target): string
    {
        return CanonicalJson::encode(['surface' => $handle->surface->toWire(), 'installation' => $handle->actor->installation->id, 'digest' => $command->digest->value,
            'action' => $request->action, 'intent' => $request->intent, 'key' => $request->subject->key, 'spec' => $request->subject->spec->toWire(),
            'proposed' => $request->proposedAudience?->toWire(), 'target' => $request->target?->toWire(), 'target_generation' => $target?->generation, 'target_privacy' => $target?->privacyRevision->value]);
    }

    private function replay(OperationHandle $handle, CommandEnvelope $command, string $context): OperationResult
    {
        $this->outside($handle);
        $this->handles()->assertCurrent(handle: $handle, namespace: $command->namespace);
        $wire = $this->decode($context);
        if (($wire['surface'] ?? null) != $handle->surface->toWire() || ($wire['installation'] ?? null) !== $handle->actor->installation->id) {
            $this->reject(ProblemCode::IdempotencyConflict);
        }
        foreach (['action', 'intent', 'key'] as $field) {
            if (! isset($wire[$field]) || ! is_string($wire[$field])) {
                $this->reject(ProblemCode::ProviderUnavailable);
            }
        }
        $request = new MutationRequest(action: $wire['action'], subject: new AudienceSubject(key: $wire['key'], spec: AudienceSpec::fromWire($this->object($wire['spec'] ?? null)), author: null, surface: $handle->surface, installation: $handle->actor->installation),
            intent: $wire['intent'], safety: AuthorizationDecision::allow('current.target'), generation: 'replay', target: ($wire['target'] ?? null) === null ? null : TargetRef::fromWire($this->object($wire['target'])),
            proposedAudience: ($wire['proposed'] ?? null) === null ? null : AudienceSpec::fromWire($this->object($wire['proposed'])));
        $owner = $this->app->make(OwnerStorage::class)->forHandle(handle: $handle, namespace: $command->namespace);
        $this->replayGuards(handle: $handle, request: $request, owner: $owner, wire: $wire);
        $receipt = new ReceiptLedger($owner)->result(envelope: $command, guardContext: $context);
        if ($receipt === null) {
            $this->reject(ProblemCode::ProviderUnavailable);
        }
        // Guards run again after waiting on a receipt lock. Never call host providers inside that tx.
        $this->replayGuards(handle: $handle, request: $request, owner: $owner, wire: $wire);
        // Content resource identities are independently guarded by their existing module-owned
        // target capability. A historical request's host visibility/audience cannot authorize
        // disclosing a resource hidden or narrowed by a later owner revision.
        if ($receipt->resource !== null && in_array($receipt->resource->kind, [ResourceKind::Post, ResourceKind::Group], true)) {
            $resolved = $this->handles()->resolveTarget(handle: $handle, target: new TargetRef(
                host: new HostEntityRef(type: $receipt->resource->kind->value, value: $receipt->resource->id), incarnation: new Incarnation(1)));
            $this->verdict(verdict: $resolved->read, write: false);
        }

        return new OperationResult(outcome: $receipt->outcome, receiptId: $receipt->id, resource: $receipt->resource, revision: $receipt->revision, problem: $receipt->problem);
    }

    /** @param array<string, mixed> $wire */
    private function replayGuards(OperationHandle $handle, MutationRequest $request, OwnerConnection $owner, array $wire): void
    {
        $this->handles()->assertCurrent($handle);
        $current = new SurfaceInstances($owner)->current();
        $this->surfaceState(handle: $handle, current: $current, create: false, write: false);
        $target = $this->target(handle: $handle, request: $request);
        if ($target !== null && ($target->generation !== ($wire['target_generation'] ?? null) || $target->privacyRevision->value !== ($wire['target_privacy'] ?? null))) {
            $this->reject(ProblemCode::AuthorizationUnknown);
        }
        $this->guards(handle: $handle, request: $request, current: $current, write: false, target: $target);
    }

    private function outside(OperationHandle $handle): void
    {
        if ($this->app->make(ConnectionResolverInterface::class)->connection($handle->panel->storage->connection)->transactionLevel() !== 0) {
            $this->reject(ProblemCode::ConfigurationInvalid);
        }
    }

    private function handles(): OperationHandles
    {
        return $this->app->make(OperationHandles::class);
    }

    private function problem(\Throwable $error): ProblemCode
    {
        return $error instanceof ProvidesProblem ? $error->problem() : ProblemCode::ProviderUnavailable;
    }

    private function reject(ProblemCode $code): never
    {
        throw new EnvelopeException($code);
    }

    /** @return array<string, mixed> */
    private function decode(string $context): array
    {
        return $this->object(json_decode($context, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            $this->reject(ProblemCode::ProviderUnavailable);
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                $this->reject(ProblemCode::ProviderUnavailable);
            } $result[$key] = $item;
        }

        return $result;
    }
}
