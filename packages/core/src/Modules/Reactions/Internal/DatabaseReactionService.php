<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Internal;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\TransactionAudienceSnapshot;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
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
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;
use SocKit\Surfaces\Exceptions\SurfaceException;

/**
 * Panel-isolated Reactions service executing through {@see OperationEnvelopeRunner},
 * rejecting read-only admission/observer verdicts on mutations, requiring a proven {@see TargetWriteFence}
 * with privacy revision verification under lock, re-verifying locked group membership locally before target lock,
 * and serializing count rebuilds with canonical lock ordering.
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

                public ?TransactionAudienceSnapshot $audienceSnapshot = null;
            };

            try {
                return $this->runtime->envelope()->run(
                    envelope: $envelope,
                    surface: $command->surface,
                    action: 'sockit.reactions.add',
                    preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $surfaceDef, $precheck): void {
                        $precheck->target = $this->resolveVisibleTarget($verifiedActor, $command->surface, $command->target, $surfaceDef, requireWrite: true);
                        $precheck->audienceSnapshot = $this->sampleTransactionSnapshotIfNeeded($verifiedActor, $command->surface, $precheck->target, $surfaceDef->archiveReaders);
                    },
                    mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $options, $namespace, $precheck): array {
                        $actorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');

                        $this->reverifyTargetInsideTx(
                            actor: $verifiedActor,
                            surface: $command->surface,
                            target: $command->target,
                            prechecked: $precheck->target,
                            snapshot: $precheck->audienceSnapshot,
                        );

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
                        $this->reverifyTargetInsideTx(
                            actor: $verifiedActor,
                            surface: $command->surface,
                            target: $command->target,
                            prechecked: $precheck->target,
                            snapshot: $precheck->audienceSnapshot,
                        );
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

                public ?TransactionAudienceSnapshot $audienceSnapshot = null;
            };

            try {
                return $this->runtime->envelope()->run(
                    envelope: $envelope,
                    surface: $command->surface,
                    action: 'sockit.reactions.remove',
                    preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $surfaceDef, $precheck): void {
                        $precheck->target = $this->resolveVisibleTarget($verifiedActor, $command->surface, $command->target, $surfaceDef, requireWrite: true);
                        $precheck->audienceSnapshot = $this->sampleTransactionSnapshotIfNeeded($verifiedActor, $command->surface, $precheck->target, $surfaceDef->archiveReaders);
                    },
                    mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace, $precheck): array {
                        $actorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                        $this->reverifyTargetInsideTx(
                            actor: $verifiedActor,
                            surface: $command->surface,
                            target: $command->target,
                            prechecked: $precheck->target,
                            snapshot: $precheck->audienceSnapshot,
                        );

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
                        $this->reverifyTargetInsideTx(
                            actor: $verifiedActor,
                            surface: $command->surface,
                            target: $command->target,
                            prechecked: $precheck->target,
                            snapshot: $precheck->audienceSnapshot,
                        );
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
        $admit = $this->runtime->audienceGate()->admitForRead($actor, $surface);
        if (! $admit->canRead()) {
            throw OperationException::audienceDenied($admit->reason);
        }

        $connection = $this->tables->connection($surface->panel);
        $surfacesTable = $this->tables->table('surface_instances', $surface->panel);
        $loadSurfaceRow = static fn (): ?object => $connection->table($surfacesTable)
            ->where('panel_id', $surface->panel->value)
            ->where('kind', $surface->kind->value)
            ->where('host_entity_type', $surface->host->type)
            ->where('host_entity_value', $surface->host->value)
            ->where('incarnation', $surface->incarnation->value)
            ->first();

        $assertSurfaceReadable = static function (?object $row) use ($surface, $surfaceDef): bool {
            if (! is_object($row)) {
                return false;
            }

            $state = SockitRows::str($row, 'lifecycle_state');
            if ($state === SurfaceLifecycleState::Deleted->value) {
                throw SurfaceException::deleted($surface->panel->value.'/'.$surface->kind->value);
            }

            $archived = $state === SurfaceLifecycleState::Archived->value;
            if ($archived && ! $surfaceDef->archiveReaders) {
                throw OperationException::audienceDenied('audience.denied.surface_archived');
            }

            return $archived;
        };

        $assertTargetState = static function (?TargetResolution $resolution) use ($target): TargetResolution {
            if (! $resolution instanceof TargetResolution || ! $resolution->exists) {
                $missingReason = $resolution instanceof TargetResolution ? $resolution->reason : null;

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

            return $resolution;
        };

        $resolver = $this->findSupportingResolver($target);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $surfaceRow = $loadSurfaceRow();
            $surfaceArchived = $assertSurfaceReadable($surfaceRow);

            $resolved = $this->resolveVisibleTarget($actor, $surface, $target, $surfaceDef, requireWrite: false, surfaceArchived: $surfaceArchived);

            // Re-verify surface & target privacy/visibility after external admission before reading counts
            $confirmSurface = $loadSurfaceRow();
            $confirmArchived = $assertSurfaceReadable($confirmSurface);
            if ($confirmArchived !== $surfaceArchived || is_object($confirmSurface) !== is_object($surfaceRow)) {
                continue;
            }

            $confirmTarget = $assertTargetState($resolver instanceof TargetResolver ? $resolver->resolve($actor, $surface, $target) : null);
            if (! $this->targetResolutionMatches($resolved, $confirmTarget)) {
                continue;
            }

            if (! is_object($confirmSurface)) {
                return new ReactionCountsView($surface, $target, [], 0, new Revision(1));
            }

            $rows = $connection->table($this->tables->table('reaction_counts', $surface->panel))
                ->where('panel_id', $surface->panel->value)
                ->where('surface_instance_id', SockitRows::str($confirmSurface, 'id'))
                ->where('target_type', $target->type)
                ->where('target_key', $target->value)
                ->orderBy('reaction_type')
                ->get();

            // Final confirmation after reading counts so concurrent deletion/narrowing cannot disclose totals
            $postReadSurface = $loadSurfaceRow();
            $postReadArchived = $assertSurfaceReadable($postReadSurface);
            $postReadTarget = $assertTargetState($resolver instanceof TargetResolver ? $resolver->resolve($actor, $surface, $target) : null);

            if (
                ! is_object($postReadSurface)
                || $postReadArchived !== $confirmArchived
                || ! $this->targetResolutionMatches($resolved, $postReadTarget)
            ) {
                continue;
            }

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

        throw OperationException::contentHidden('target.concurrent_mutation');
    }

    private function targetResolutionMatches(TargetResolution $expected, TargetResolution $current): bool
    {
        $ownerMatches = match (true) {
            $expected->owner instanceof ActorRef && $current->owner instanceof ActorRef => $expected->owner->equals($current->owner),
            ! $expected->owner instanceof ActorRef && ! $current->owner instanceof ActorRef => true,
            default => false,
        };
        if (! $ownerMatches) {
            return false;
        }

        if ($expected->privacyRevision?->value !== $current->privacyRevision?->value) {
            return false;
        }

        $expectedWire = $expected->audience instanceof AudienceSpec ? CanonicalJson::encode($expected->audience->toWire()) : null;
        $currentWire = $current->audience instanceof AudienceSpec ? CanonicalJson::encode($current->audience->toWire()) : null;

        return $expectedWire === $currentWire;
    }

    #[Override]
    public function rebuildCounts(PanelId $panel): int
    {
        $panelDef = $this->panels->get($panel);

        if (! $panelDef->hasModule('reactions')) {
            throw PanelException::bindingMismatch($panel->value, "Panel [{$panel->value}] does not enable the reactions module.");
        }

        $connection = $this->tables->connection($panel);
        $surfacesTable = $this->tables->table('surface_instances', $panel);
        $reactionsTable = $this->tables->table('reactions', $panel);
        $countsTable = $this->tables->table('reaction_counts', $panel);
        $outboxTable = $this->tables->table('outbox_events', $panel);

        return $connection->transaction(function () use ($connection, $surfacesTable, $reactionsTable, $countsTable, $outboxTable, $panel): int {
            // Canonical lock order: lock panel surface_instances in deterministic order before reaction_counts
            // so concurrent first-reaction inserts cannot race rebuildCounts() across empty count buckets.
            $surfaceLockQuery = $connection->table($surfacesTable)
                ->select('id')
                ->where('panel_id', $panel->value)
                ->orderBy('id');

            if ($connection->getDriverName() !== 'sqlite') {
                $surfaceLockQuery->lockForUpdate();
            }

            $surfaceLockQuery->get();

            $existingQuery = $connection->table($countsTable)
                ->where('panel_id', $panel->value)
                ->orderBy('surface_instance_id')
                ->orderBy('target_type')
                ->orderBy('target_key')
                ->orderBy('reaction_type');
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
                    $this->upsertRebuiltBucket(
                        connection: $connection,
                        countsTable: $countsTable,
                        panelId: $panel->value,
                        surfaceInstanceId: $sId,
                        targetType: $tType,
                        targetKey: $tKey,
                        reactionType: $rType,
                        aggCount: $aggCount,
                        maxOutboxRev: $maxOutboxRev,
                    );
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
        bool $surfaceArchived = false,
    ): TargetResolution {
        $audienceGate = $this->runtime->audienceGate();
        $admit = $requireWrite
            ? $audienceGate->admitForWrite($actor, $surface)
            : $audienceGate->admitForRead($actor, $surface);

        if ($requireWrite ? ! $admit->canWrite() : ! $admit->canRead()) {
            throw OperationException::audienceDenied($admit->reason);
        }

        $resolver = $this->findSupportingResolver($target);

        if ($requireWrite) {
            if (! $resolver instanceof TargetWriteFence) {
                throw AuthorizationDeniedException::unknown('reaction.target_fence_required');
            }
        }

        $resolution = $resolver instanceof TargetResolver
            ? $resolver->resolve($actor, $surface, $target)
            : null;

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

        if ($requireWrite && ! $resolution->privacyRevision instanceof Revision) {
            throw AuthorizationDeniedException::unknown('reaction.target_fence_required');
        }

        if ($resolution->audience instanceof AudienceSpec) {
            $verdict = $audienceGate->read($actor, new AudienceSubject(
                key: $target->toWire(),
                spec: $resolution->audience,
                author: $resolution->owner,
                surface: $surface,
                installation: $actor->installation,
                surfaceArchived: $surfaceArchived,
                archiveReaders: $surfaceDef->archiveReaders,
            ));

            if ($requireWrite ? ! $verdict->canWrite() : ! $verdict->canRead()) {
                throw OperationException::audienceDenied($verdict->reason);
            }
        }

        return $resolution;
    }

    private function sampleTransactionSnapshotIfNeeded(
        ActorContext $actor,
        SurfaceRef $surface,
        ?TargetResolution $resolution,
        bool $archiveReaders,
    ): ?TransactionAudienceSnapshot {
        if (! $resolution instanceof TargetResolution || ! $resolution->audience instanceof AudienceSpec) {
            return null;
        }

        if ($resolution->audience->referencedGroups() === []) {
            return null;
        }

        return $this->runtime->audienceGate()->sampleTransactionSnapshot($actor, $resolution->audience, $surface->panel, $archiveReaders);
    }

    private function reverifyTargetInsideTx(
        ActorContext $actor,
        SurfaceRef $surface,
        HostEntityRef $target,
        ?TargetResolution $prechecked,
        ?TransactionAudienceSnapshot $snapshot = null,
    ): void {
        $resolver = $this->findSupportingResolver($target);

        if (! $resolver instanceof TargetWriteFence || ! $prechecked instanceof TargetResolution || ! $prechecked->privacyRevision instanceof Revision) {
            throw AuthorizationDeniedException::unknown('reaction.target_fence_required');
        }

        if ($prechecked->audience instanceof AudienceSpec && $prechecked->audience->referencedGroups() !== []) {
            $groupVerdict = $this->runtime->audienceGate()->reverifyLockedGroupsInTransaction(
                actor: $actor,
                spec: $prechecked->audience,
                panel: $surface->panel,
                snapshot: $snapshot,
            );

            if (! $groupVerdict->canWrite()) {
                throw OperationException::audienceDenied($groupVerdict->reason);
            }
        }

        $decision = $resolver->recheck($actor, $surface, $target, $prechecked);

        if ($decision->isAllowed()) {
            return;
        }

        if ($decision->reason === 'target.privacy_revision_stale') {
            throw new ReactionTargetStaleException('Target audience or privacy revision changed after pre-transaction authorization.');
        }

        if ($decision->isUnknown()) {
            throw AuthorizationDeniedException::unknown($decision->reason);
        }

        if ($decision->reason === 'content.deleted') {
            throw OperationException::contentDeleted($target->value);
        }

        if ($decision->reason === 'content.erased') {
            throw OperationException::contentErased($target->value);
        }

        if (
            str_starts_with($decision->reason, 'content.')
            || str_starts_with($decision->reason, 'target.')
            || str_starts_with($decision->reason, 'post.')
            || str_starts_with($decision->reason, 'group.')
        ) {
            throw OperationException::contentHidden($decision->reason);
        }

        throw AuthorizationDeniedException::denied($decision->reason);
    }

    private function findSupportingResolver(HostEntityRef $target): ?TargetResolver
    {
        foreach ($this->runtime->targetResolvers() as $resolver) {
            if ($resolver->supports($target)) {
                return $resolver;
            }
        }

        return null;
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

            try {
                $connection->transaction(static function () use ($connection, $countsTable, $panelId, $surfaceInstanceId, $target, $reactionType, $initialCount, $initialRevision): void {
                    $connection->table($countsTable)->insert([
                        'panel_id' => $panelId,
                        'surface_instance_id' => $surfaceInstanceId,
                        'target_type' => $target->type,
                        'target_key' => $target->value,
                        'reaction_type' => $reactionType,
                        'count' => $initialCount,
                        'source_revision' => $initialRevision,
                    ]);
                });

                return new Revision($initialRevision);
            } catch (QueryException $exception) {
                $sqlState = $exception->errorInfo[0] ?? null;
                if (! in_array($sqlState, ['23505', '23000'], true)) {
                    throw $exception;
                }

                $row = $query->first();
                if (! is_object($row)) {
                    throw $exception;
                }
            }
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

    private function upsertRebuiltBucket(
        Connection $connection,
        string $countsTable,
        string $panelId,
        string $surfaceInstanceId,
        string $targetType,
        string $targetKey,
        string $reactionType,
        int $aggCount,
        int $maxOutboxRev,
    ): void {
        try {
            $connection->transaction(static function () use ($connection, $countsTable, $panelId, $surfaceInstanceId, $targetType, $targetKey, $reactionType, $aggCount, $maxOutboxRev): void {
                $connection->table($countsTable)->insert([
                    'panel_id' => $panelId,
                    'surface_instance_id' => $surfaceInstanceId,
                    'target_type' => $targetType,
                    'target_key' => $targetKey,
                    'reaction_type' => $reactionType,
                    'count' => $aggCount,
                    'source_revision' => max(1, $maxOutboxRev + 1),
                ]);
            });
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            if (! in_array($sqlState, ['23505', '23000'], true)) {
                throw $exception;
            }

            $existingQuery = $connection->table($countsTable)
                ->where('panel_id', $panelId)
                ->where('surface_instance_id', $surfaceInstanceId)
                ->where('target_type', $targetType)
                ->where('target_key', $targetKey)
                ->where('reaction_type', $reactionType);

            if ($connection->getDriverName() !== 'sqlite') {
                $existingQuery->lockForUpdate();
            }

            $existing = $existingQuery->first();
            if (! is_object($existing)) {
                throw $exception;
            }

            $baseRev = max(SockitRows::int($existing, 'source_revision', 1), $maxOutboxRev);
            $nextRev = SockitRows::int($existing, 'count') !== $aggCount ? $baseRev + 1 : $baseRev;

            $connection->table($countsTable)
                ->where('panel_id', $panelId)
                ->where('surface_instance_id', $surfaceInstanceId)
                ->where('target_type', $targetType)
                ->where('target_key', $targetKey)
                ->where('reaction_type', $reactionType)
                ->update([
                    'count' => $aggCount,
                    'source_revision' => $nextRev,
                ]);
        }
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
