<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Internal;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Modules\Reactions\Configuration\ReactionsOptions;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Modules\Reactions\Data\ReactionCountsView;
use SocKit\Modules\Reactions\Data\RemoveReactionCommand;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\LocksTargetInTransaction;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;

/**
 * Panel-isolated Reactions service executing through {@see OperationEnvelopeRunner},
 * rejecting read-only surface admission/observer verdicts on mutations, verifying same-DB
 * target privacy revision & audience under lock without holding locks across remote resolvers,
 * and preserving monotonic surface+target+type outbox identity across rebuilds.
 */
#[Internal]
final readonly class DatabaseReactionService implements ReactionService
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private SockitRuntime $runtime,
    ) {}

    #[Override]
    public function add(AddReactionCommand $command): OperationResult
    {
        [$options, $surfaceDef] = $this->assertReactionsEnabled($command->surface);

        if (! $options->allows($command->reactionType)) {
            throw InvalidPayloadException::invalid(
                "Reaction type [{$command->reactionType}] is not allowed on panel [{$command->surface->panel->value}].",
                ['reaction_type' => $command->reactionType],
                '/reaction_type',
            );
        }

        $actor = $this->runtime->actors()->current();
        $digest = PayloadDigest::of('reactions.add', $command->toDigestPayload());
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'reactions.add');
        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $precheck = new class
            {
                public ?TargetResolution $target = null;
            };

            try {
                return $this->runtime->envelope()->run(
                    envelope: $envelope,
                    surface: $command->surface,
                    action: 'sockit.reactions.add',
                    preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $surfaceDef, $precheck): void {
                        $this->runtime->authorizer()->decide(new ActionRequest(
                            actor: $verifiedActor,
                            action: 'sockit.reactions.add',
                            surface: $command->surface,
                        ))->requireAllowed();

                        $precheck->target = $this->resolveVisibleTarget($verifiedActor, $command->surface, $command->target, $surfaceDef, requireWrite: true);
                    },
                    mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $options, $namespace, $precheck): array {
                        $actorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');

                        $this->reverifyTargetInsideTx($verifiedActor, $command->surface, $command->target, $precheck->target);

                        $surfaceInstanceId = $this->findSurfaceInstanceId($connection, $command->surface);
                        $reactionsTable = $this->tables->table('reactions', $command->surface->panel);
                        $countsTable = $this->tables->table('reaction_counts', $command->surface->panel);

                        $existing = $connection->table($reactionsTable)
                            ->where('panel_id', $command->surface->panel->value)
                            ->where('surface_instance_id', $surfaceInstanceId)
                            ->where('target_type', $command->target->type)
                            ->where('target_key', $command->target->value)
                            ->where('actor_type', $actorRef->type)
                            ->where('actor_id', $actorRef->id)
                            ->where('reaction_type', $command->reactionType)
                            ->first();

                        if (is_object($existing)) {
                            $revision = $this->currentRevision($connection, $countsTable, $command->surface->panel->value, $surfaceInstanceId, $command->target, $command->reactionType);

                            return [
                                'result' => OperationResult::confirmed(
                                    $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value),
                                    new ResourceRef($command->surface->panel, ResourceKind::Reaction, SockitRows::str($existing, 'id')),
                                    $revision,
                                ),
                                'event' => null,
                            ];
                        }

                        $actorDistinctCount = $connection->table($reactionsTable)
                            ->where('panel_id', $command->surface->panel->value)
                            ->where('surface_instance_id', $surfaceInstanceId)
                            ->where('target_type', $command->target->type)
                            ->where('target_key', $command->target->value)
                            ->where('actor_type', $actorRef->type)
                            ->where('actor_id', $actorRef->id)
                            ->count();

                        if ($actorDistinctCount >= $options->maxPerTarget) {
                            throw InvalidPayloadException::invalid(
                                "Actor has reached maximum distinct reactions [{$options->maxPerTarget}] per target.",
                                ['max_per_target' => $options->maxPerTarget],
                            );
                        }

                        $reactionId = Ids::uuid7();
                        $inserted = true;

                        try {
                            $connection->transaction(static function () use ($connection, $reactionsTable, $reactionId, $command, $surfaceInstanceId, $actorRef): void {
                                $connection->table($reactionsTable)->insert([
                                    'id' => $reactionId,
                                    'panel_id' => $command->surface->panel->value,
                                    'surface_instance_id' => $surfaceInstanceId,
                                    'target_type' => $command->target->type,
                                    'target_key' => $command->target->value,
                                    'actor_type' => $actorRef->type,
                                    'actor_id' => $actorRef->id,
                                    'reaction_type' => $command->reactionType,
                                    'created_at' => gmdate('Y-m-d H:i:s.uP'),
                                ]);
                            });
                        } catch (QueryException $exception) {
                            $sqlState = $exception->errorInfo[0] ?? null;
                            if (! in_array($sqlState, ['23505', '23000'], true)) {
                                throw $exception;
                            }
                            $inserted = false;
                        }

                        $revision = new Revision(1);
                        $event = null;

                        if ($inserted) {
                            $revision = $this->adjustCount($connection, $countsTable, $command->surface->panel->value, $surfaceInstanceId, $command->target, $command->reactionType, 1);
                            $event = [
                                'aggregate_type' => 'reaction_target',
                                'aggregate_id' => $this->reactionAggregateId($surfaceInstanceId, $command->target, $command->reactionType),
                                'aggregate_revision' => $revision->value,
                                'event_type' => 'sockit.reactions.added',
                                'payload' => [
                                    'panel' => $command->surface->panel->value,
                                    'surface' => $command->surface->toWire(),
                                    'surface_instance_id' => $surfaceInstanceId,
                                    'target' => $command->target->toWire(),
                                    'reaction_type' => $command->reactionType,
                                ],
                            ];
                        }

                        return [
                            'result' => OperationResult::confirmed(
                                $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value),
                                new ResourceRef($command->surface->panel, ResourceKind::Reaction, $reactionId),
                                $revision,
                            ),
                            'event' => $event,
                        ];
                    },
                    replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($command, $precheck): void {
                        $this->reverifyTargetInsideTx($verifiedActor, $command->surface, $command->target, $precheck->target);
                    },
                );
            } catch (ReactionTargetStaleException) {
                if ($attempt === 2) {
                    $rev = $precheck->target instanceof TargetResolution && $precheck->target->privacyRevision instanceof Revision
                        ? $precheck->target->privacyRevision->value
                        : 1;

                    throw OperationException::revisionConflict($rev, $rev + 1);
                }
            }
        }

        throw OperationException::revisionConflict(1, 2);
    }

    #[Override]
    public function remove(RemoveReactionCommand $command): OperationResult
    {
        [, $surfaceDef] = $this->assertReactionsEnabled($command->surface);

        $actor = $this->runtime->actors()->current();
        $digest = PayloadDigest::of('reactions.remove', $command->toDigestPayload());
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'reactions.remove');
        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $precheck = new class
            {
                public ?TargetResolution $target = null;
            };

            try {
                return $this->runtime->envelope()->run(
                    envelope: $envelope,
                    surface: $command->surface,
                    action: 'sockit.reactions.remove',
                    preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $surfaceDef, $precheck): void {
                        $this->runtime->authorizer()->decide(new ActionRequest(
                            actor: $verifiedActor,
                            action: 'sockit.reactions.remove',
                            surface: $command->surface,
                        ))->requireAllowed();

                        $precheck->target = $this->resolveVisibleTarget($verifiedActor, $command->surface, $command->target, $surfaceDef, requireWrite: true);
                    },
                    mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace, $precheck): array {
                        $actorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                        $this->reverifyTargetInsideTx($verifiedActor, $command->surface, $command->target, $precheck->target);

                        $surfaceInstanceId = $this->findSurfaceInstanceId($connection, $command->surface);
                        $reactionsTable = $this->tables->table('reactions', $command->surface->panel);
                        $countsTable = $this->tables->table('reaction_counts', $command->surface->panel);

                        $existing = $connection->table($reactionsTable)
                            ->where('panel_id', $command->surface->panel->value)
                            ->where('surface_instance_id', $surfaceInstanceId)
                            ->where('target_type', $command->target->type)
                            ->where('target_key', $command->target->value)
                            ->where('actor_type', $actorRef->type)
                            ->where('actor_id', $actorRef->id)
                            ->where('reaction_type', $command->reactionType)
                            ->first();

                        if (! is_object($existing)) {
                            $revision = $this->currentRevision($connection, $countsTable, $command->surface->panel->value, $surfaceInstanceId, $command->target, $command->reactionType);

                            return [
                                'result' => OperationResult::confirmed(
                                    $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value),
                                    new ResourceRef($command->surface->panel, ResourceKind::Reaction, 'none'),
                                    $revision,
                                ),
                                'event' => null,
                            ];
                        }

                        $existingId = SockitRows::str($existing, 'id');
                        $deleted = $connection->table($reactionsTable)
                            ->where('id', $existingId)
                            ->delete();

                        $revision = $deleted > 0
                            ? $this->adjustCount($connection, $countsTable, $command->surface->panel->value, $surfaceInstanceId, $command->target, $command->reactionType, -1)
                            : $this->currentRevision($connection, $countsTable, $command->surface->panel->value, $surfaceInstanceId, $command->target, $command->reactionType);

                        return [
                            'result' => OperationResult::confirmed(
                                $this->findReceiptId($connection, $command->surface->panel, $namespace->toWire(), $command->key->value),
                                new ResourceRef($command->surface->panel, ResourceKind::Reaction, $existingId),
                                $revision,
                            ),
                            'event' => $deleted > 0 ? [
                                'aggregate_type' => 'reaction_target',
                                'aggregate_id' => $this->reactionAggregateId($surfaceInstanceId, $command->target, $command->reactionType),
                                'aggregate_revision' => $revision->value,
                                'event_type' => 'sockit.reactions.removed',
                                'payload' => [
                                    'panel' => $command->surface->panel->value,
                                    'surface' => $command->surface->toWire(),
                                    'surface_instance_id' => $surfaceInstanceId,
                                    'target' => $command->target->toWire(),
                                    'reaction_type' => $command->reactionType,
                                ],
                            ] : null,
                        ];
                    },
                    replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($command, $precheck): void {
                        $this->reverifyTargetInsideTx($verifiedActor, $command->surface, $command->target, $precheck->target);
                    },
                );
            } catch (ReactionTargetStaleException) {
                if ($attempt === 2) {
                    $rev = $precheck->target instanceof TargetResolution && $precheck->target->privacyRevision instanceof Revision
                        ? $precheck->target->privacyRevision->value
                        : 1;

                    throw OperationException::revisionConflict($rev, $rev + 1);
                }
            }
        }

        throw OperationException::revisionConflict(1, 2);
    }

    #[Override]
    public function counts(SurfaceRef $surface, HostEntityRef $target): ReactionCountsView
    {
        [, $surfaceDef] = $this->assertReactionsEnabled($surface);
        $actor = $this->runtime->actors()->current();

        $this->resolveVisibleTarget($actor, $surface, $target, $surfaceDef, requireWrite: false);

        $connection = $this->tables->connection($surface->panel);
        $surfaceRow = $connection->table($this->tables->table('surface_instances', $surface->panel))
            ->where('panel_id', $surface->panel->value)
            ->where('kind', $surface->kind->value)
            ->where('host_entity_type', $surface->host->type)
            ->where('host_entity_value', $surface->host->value)
            ->where('incarnation', $surface->incarnation->value)
            ->first();

        if (! is_object($surfaceRow)) {
            return new ReactionCountsView($surface, $target, [], 0, new Revision(1));
        }

        $rows = $connection->table($this->tables->table('reaction_counts', $surface->panel))
            ->where('panel_id', $surface->panel->value)
            ->where('surface_instance_id', SockitRows::str($surfaceRow, 'id'))
            ->where('target_type', $target->type)
            ->where('target_key', $target->value)
            ->orderBy('reaction_type')
            ->get();

        $counts = [];
        $total = 0;
        $maxRevision = 1;

        foreach ($rows as $row) {
            $count = SockitRows::int($row, 'count');
            $maxRevision = max($maxRevision, SockitRows::int($row, 'source_revision', 1));
            if ($count > 0) {
                $counts[SockitRows::str($row, 'reaction_type')] = $count;
                $total += $count;
            }
        }

        return new ReactionCountsView($surface, $target, $counts, $total, new Revision($maxRevision));
    }

    #[Override]
    public function rebuildCounts(PanelId $panel): int
    {
        $panelDef = $this->panels->get($panel);

        if (! $panelDef->hasModule('reactions')) {
            throw PanelException::bindingMismatch($panel->value, "Panel [{$panel->value}] does not enable the reactions module.");
        }

        $connection = $this->tables->connection($panel);
        $reactionsTable = $this->tables->table('reactions', $panel);
        $countsTable = $this->tables->table('reaction_counts', $panel);
        $outboxTable = $this->tables->table('outbox_events', $panel);

        return $connection->transaction(function () use ($connection, $reactionsTable, $countsTable, $outboxTable, $panel): int {
            $existingQuery = $connection->table($countsTable)->where('panel_id', $panel->value);
            if ($connection->getDriverName() !== 'sqlite') {
                $existingQuery->lockForUpdate();
            }

            /** @var array<string, array{surface_instance_id: string, target_type: string, target_key: string, reaction_type: string, count: int, source_revision: int}> $existingMap */
            $existingMap = [];
            foreach ($existingQuery->get() as $existingRow) {
                $sId = SockitRows::str($existingRow, 'surface_instance_id');
                $tType = SockitRows::str($existingRow, 'target_type');
                $tKey = SockitRows::str($existingRow, 'target_key');
                $rType = SockitRows::str($existingRow, 'reaction_type');
                $existingMap[$this->bucketKey($sId, $tType, $tKey, $rType)] = [
                    'surface_instance_id' => $sId,
                    'target_type' => $tType,
                    'target_key' => $tKey,
                    'reaction_type' => $rType,
                    'count' => SockitRows::int($existingRow, 'count'),
                    'source_revision' => SockitRows::int($existingRow, 'source_revision', 1),
                ];
            }

            /** @var array<string, int> $outboxMax */
            $outboxMax = [];
            $outboxRows = $connection->table($outboxTable)
                ->selectRaw('aggregate_id, MAX(aggregate_revision) as max_rev')
                ->where('panel_id', $panel->value)
                ->where('aggregate_type', 'reaction_target')
                ->groupBy('aggregate_id')
                ->get();
            foreach ($outboxRows as $oRow) {
                $outboxMax[SockitRows::str($oRow, 'aggregate_id')] = SockitRows::int($oRow, 'max_rev', 0);
            }

            $rows = $connection->table($reactionsTable)
                ->selectRaw('panel_id, surface_instance_id, target_type, target_key, reaction_type, COUNT(*) as agg_count')
                ->where('panel_id', $panel->value)
                ->groupBy(['panel_id', 'surface_instance_id', 'target_type', 'target_key', 'reaction_type'])
                ->get();

            $written = 0;
            $seenBuckets = [];

            foreach ($rows as $row) {
                $sId = SockitRows::str($row, 'surface_instance_id');
                $tType = SockitRows::str($row, 'target_type');
                $tKey = SockitRows::str($row, 'target_key');
                $rType = SockitRows::str($row, 'reaction_type');
                $aggCount = SockitRows::int($row, 'agg_count');
                $bucketKey = $this->bucketKey($sId, $tType, $tKey, $rType);
                $seenBuckets[$bucketKey] = true;

                $aggId = $this->reactionAggregateId($sId, new HostEntityRef($tType, $tKey), $rType);
                $maxOutboxRev = $outboxMax[$aggId] ?? 0;

                if (isset($existingMap[$bucketKey])) {
                    $old = $existingMap[$bucketKey];
                    $baseRev = max($old['source_revision'], $maxOutboxRev);
                    $nextRev = $old['count'] !== $aggCount ? $baseRev + 1 : $baseRev;

                    $connection->table($countsTable)
                        ->where('panel_id', $panel->value)
                        ->where('surface_instance_id', $sId)
                        ->where('target_type', $tType)
                        ->where('target_key', $tKey)
                        ->where('reaction_type', $rType)
                        ->update([
                            'count' => $aggCount,
                            'source_revision' => $nextRev,
                        ]);
                } else {
                    $connection->table($countsTable)->insert([
                        'panel_id' => $panel->value,
                        'surface_instance_id' => $sId,
                        'target_type' => $tType,
                        'target_key' => $tKey,
                        'reaction_type' => $rType,
                        'count' => $aggCount,
                        'source_revision' => max(1, $maxOutboxRev + 1),
                    ]);
                }

                $written++;
            }

            // Preserve zeroed reaction_counts buckets with monotonic source_revision rather than deleting history.
            foreach ($existingMap as $bucketKey => $old) {
                if (isset($seenBuckets[$bucketKey])) {
                    continue;
                }

                $aggId = $this->reactionAggregateId(
                    $old['surface_instance_id'],
                    new HostEntityRef($old['target_type'], $old['target_key']),
                    $old['reaction_type'],
                );
                $baseRev = max($old['source_revision'], $outboxMax[$aggId] ?? 0);
                $nextRev = $old['count'] !== 0 ? $baseRev + 1 : $baseRev;

                $connection->table($countsTable)
                    ->where('panel_id', $panel->value)
                    ->where('surface_instance_id', $old['surface_instance_id'])
                    ->where('target_type', $old['target_type'])
                    ->where('target_key', $old['target_key'])
                    ->where('reaction_type', $old['reaction_type'])
                    ->update([
                        'count' => 0,
                        'source_revision' => $nextRev,
                    ]);
            }

            return $written;
        });
    }

    /** @return array{0: ReactionsOptions, 1: SurfaceDefinition} */
    private function assertReactionsEnabled(SurfaceRef $surface): array
    {
        $panelDef = $this->panels->get($surface->panel);
        $surfaceDef = $this->surfaces->get($surface->panel, $surface->kind);

        if (! $panelDef->hasModule('reactions')) {
            throw PanelException::bindingMismatch($surface->panel->value, "Panel [{$surface->panel->value}] does not enable module [reactions].");
        }

        if ($surfaceDef->features !== [] && ! $surfaceDef->supportsFeature('reactions')) {
            throw PanelException::bindingMismatch($surface->panel->value, "Surface [{$surfaceDef->key()}] does not enable feature [reactions].");
        }

        return [ReactionsOptions::fromSettings($panelDef->settingsFor('reactions')), $surfaceDef];
    }

    private function resolveVisibleTarget(
        ActorContext $actor,
        SurfaceRef $surface,
        HostEntityRef $target,
        SurfaceDefinition $surfaceDef,
        bool $requireWrite,
    ): TargetResolution {
        $audienceGate = $this->runtime->audienceGate();
        $admit = $requireWrite
            ? $audienceGate->admitForWrite($actor, $surface)
            : $audienceGate->admitForRead($actor, $surface);

        if ($requireWrite ? ! $admit->canWrite() : ! $admit->canRead()) {
            throw OperationException::audienceDenied($admit->reason);
        }

        $resolution = null;
        foreach ($this->runtime->targetResolvers() as $resolver) {
            if ($resolver->supports($target)) {
                $resolution = $resolver->resolve($actor, $surface, $target);

                break;
            }
        }

        $missingReason = $resolution instanceof TargetResolution ? $resolution->reason : null;
        if (! $resolution instanceof TargetResolution || ! $resolution->exists) {
            throw match ($missingReason) {
                'content.deleted' => OperationException::contentDeleted($target->value),
                'content.erased' => OperationException::contentErased($target->value),
                default => OperationException::contentHidden($missingReason ?? 'target.not_found'),
            };
        }

        if (! $resolution->visible) {
            throw match ($resolution->reason) {
                'content.deleted' => OperationException::contentDeleted($target->value),
                'content.erased' => OperationException::contentErased($target->value),
                default => OperationException::contentHidden($resolution->reason ?? 'target.hidden'),
            };
        }

        if ($resolution->audience instanceof AudienceSpec) {
            $verdict = $audienceGate->read($actor, new AudienceSubject(
                key: $target->toWire(),
                spec: $resolution->audience,
                author: $resolution->owner,
                surface: $surface,
                installation: $actor->installation,
                surfaceArchived: false,
                archiveReaders: $surfaceDef->archiveReaders,
            ));

            if ($requireWrite ? ! $verdict->canWrite() : ! $verdict->canRead()) {
                throw OperationException::audienceDenied($verdict->reason);
            }
        }

        return $resolution;
    }

    private function reverifyTargetInsideTx(
        ActorContext $actor,
        SurfaceRef $surface,
        HostEntityRef $target,
        ?TargetResolution $prechecked,
    ): void {
        $lockingResolver = null;
        foreach ($this->runtime->targetResolvers() as $resolver) {
            if ($resolver->supports($target)) {
                if ($resolver instanceof LocksTargetInTransaction) {
                    $lockingResolver = $resolver;
                }

                break;
            }
        }

        // Never invoke external/arbitrary host TargetResolvers while holding DB transaction locks.
        if (! $lockingResolver instanceof LocksTargetInTransaction) {
            return;
        }

        $txResolution = $lockingResolver->resolve($actor, $surface, $target);

        if (! $txResolution->exists) {
            throw match ($txResolution->reason) {
                'content.deleted' => OperationException::contentDeleted($target->value),
                'content.erased' => OperationException::contentErased($target->value),
                default => OperationException::contentHidden($txResolution->reason ?? 'target.not_found'),
            };
        }

        if (! $txResolution->visible) {
            throw match ($txResolution->reason) {
                'content.deleted' => OperationException::contentDeleted($target->value),
                'content.erased' => OperationException::contentErased($target->value),
                default => OperationException::contentHidden($txResolution->reason ?? 'target.hidden'),
            };
        }

        $preRevision = $prechecked?->privacyRevision?->value;
        $txRevision = $txResolution->privacyRevision?->value;
        $preAudience = $prechecked?->audience?->toWire();
        $txAudience = $txResolution->audience?->toWire();

        if ($preRevision !== $txRevision || $preAudience !== $txAudience) {
            throw new ReactionTargetStaleException('Target audience or privacy revision changed after pre-transaction authorization.');
        }
    }

    private function findSurfaceInstanceId(Connection $connection, SurfaceRef $surface): string
    {
        $row = $connection->table($this->tables->table('surface_instances', $surface->panel))
            ->where('panel_id', $surface->panel->value)
            ->where('kind', $surface->kind->value)
            ->where('host_entity_type', $surface->host->type)
            ->where('host_entity_value', $surface->host->value)
            ->where('incarnation', $surface->incarnation->value)
            ->first();

        return is_object($row) ? SockitRows::str($row, 'id') : Ids::uuid7();
    }

    private function adjustCount(
        Connection $connection,
        string $countsTable,
        string $panelId,
        string $surfaceInstanceId,
        HostEntityRef $target,
        string $reactionType,
        int $delta,
    ): Revision {
        $query = $connection->table($countsTable)
            ->where('panel_id', $panelId)
            ->where('surface_instance_id', $surfaceInstanceId)
            ->where('target_type', $target->type)
            ->where('target_key', $target->value)
            ->where('reaction_type', $reactionType);

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! is_object($row)) {
            $aggregateId = $this->reactionAggregateId($surfaceInstanceId, $target, $reactionType);
            $maxOutboxRaw = $connection->table($this->tables->table('outbox_events', new PanelId($panelId)))
                ->where('panel_id', $panelId)
                ->where('aggregate_type', 'reaction_target')
                ->where('aggregate_id', $aggregateId)
                ->max('aggregate_revision');
            $maxOutbox = is_int($maxOutboxRaw) || is_string($maxOutboxRaw) ? (int) $maxOutboxRaw : 0;
            $initialRevision = max(1, $maxOutbox + 1);
            $initialCount = max(0, $delta);

            $connection->table($countsTable)->insert([
                'panel_id' => $panelId,
                'surface_instance_id' => $surfaceInstanceId,
                'target_type' => $target->type,
                'target_key' => $target->value,
                'reaction_type' => $reactionType,
                'count' => $initialCount,
                'source_revision' => $initialRevision,
            ]);

            return new Revision($initialRevision);
        }

        $nextCount = max(0, SockitRows::int($row, 'count') + $delta);
        $nextRevision = SockitRows::int($row, 'source_revision', 1) + 1;

        $connection->table($countsTable)
            ->where('panel_id', $panelId)
            ->where('surface_instance_id', $surfaceInstanceId)
            ->where('target_type', $target->type)
            ->where('target_key', $target->value)
            ->where('reaction_type', $reactionType)
            ->update([
                'count' => $nextCount,
                'source_revision' => $nextRevision,
            ]);

        return new Revision($nextRevision);
    }

    private function currentRevision(
        Connection $connection,
        string $countsTable,
        string $panelId,
        string $surfaceInstanceId,
        HostEntityRef $target,
        string $reactionType,
    ): Revision {
        $row = $connection->table($countsTable)
            ->where('panel_id', $panelId)
            ->where('surface_instance_id', $surfaceInstanceId)
            ->where('target_type', $target->type)
            ->where('target_key', $target->value)
            ->where('reaction_type', $reactionType)
            ->first();

        return is_object($row) ? new Revision(max(1, SockitRows::int($row, 'source_revision', 1))) : new Revision(1);
    }

    private function findReceiptId(Connection $connection, PanelId $panel, string $namespace, string $commandKey): string
    {
        $row = $connection->table($this->tables->table('command_receipts', $panel))
            ->where('panel_id', $panel->value)
            ->where('command_namespace', $namespace)
            ->where('command_key', $commandKey)
            ->first();

        return is_object($row) ? SockitRows::str($row, 'id') : Ids::uuid7();
    }

    private function reactionAggregateId(string $surfaceInstanceId, HostEntityRef $target, string $reactionType): string
    {
        return hash('sha256', CanonicalJson::encode([
            'surface_instance_id' => $surfaceInstanceId,
            'target_type' => $target->type,
            'target_key' => $target->value,
            'reaction_type' => $reactionType,
        ]));
    }

    private function bucketKey(string $surfaceInstanceId, string $targetType, string $targetKey, string $reactionType): string
    {
        return $surfaceInstanceId.'|'.$targetType.'|'.$targetKey.'|'.$reactionType;
    }
}
