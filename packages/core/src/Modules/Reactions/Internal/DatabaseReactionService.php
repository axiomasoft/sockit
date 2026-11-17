<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Internal;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
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
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\PanelException;
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
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;

/**
 * Panel-isolated Reactions service executing through {@see OperationEnvelopeRunner}
 * and resolving targets exclusively via the {@see TargetResolver} slot.
 */
#[Internal]
final readonly class DatabaseReactionService implements ReactionService
{
    /** @param list<TargetResolver> $targetResolvers */
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private ActorContexts $actors,
        private OperationEnvelopeRunner $envelope,
        private ActionAuthorizer $authorizer,
        private AudienceGate $audienceGate,
        private ?SurfaceAudiencePolicy $surfacePolicy = null,
        private array $targetResolvers = [],
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

        $actor = $this->actors->current();
        $digest = PayloadDigest::of('reactions.add', $command->toDigestPayload());
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'reactions.add');
        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
        );

        return $this->envelope->run(
            envelope: $envelope,
            surface: $command->surface,
            action: 'sockit.reactions.add',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $surfaceDef): void {
                $this->authorizer->decide(new ActionRequest(
                    actor: $verifiedActor,
                    action: 'sockit.reactions.add',
                    surface: $command->surface,
                ))->requireAllowed();

                $this->resolveVisibleTarget($verifiedActor, $command->surface, $command->target, $surfaceDef);
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $options, $namespace): array {
                $actorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');

                // Re-resolve target inside TX so same-DB host/post targets serialize reaction vs hide/delete.
                $this->reverifyTargetInsideTx($verifiedActor, $command->surface, $command->target);

                $surfaceInstanceId = $this->findSurfaceInstanceId($connection, $command->surface);
                $reactionsTable = $this->tables->table('reactions', $command->surface->panel);
                $countsTable = $this->tables->table('reaction_counts', $command->surface->panel);

                $existingQuery = $connection->table($reactionsTable)
                    ->where('panel_id', $command->surface->panel->value)
                    ->where('surface_instance_id', $surfaceInstanceId)
                    ->where('target_type', $command->target->type)
                    ->where('target_key', $command->target->value)
                    ->where('actor_type', $actorRef->type)
                    ->where('actor_id', $actorRef->id)
                    ->where('reaction_type', $command->reactionType);

                $existing = $existingQuery->first();

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
                        'aggregate_id' => $command->target->toWire(),
                        'aggregate_revision' => $revision->value,
                        'event_type' => 'sockit.reactions.added',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
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
        );
    }

    #[Override]
    public function remove(RemoveReactionCommand $command): OperationResult
    {
        [, $surfaceDef] = $this->assertReactionsEnabled($command->surface);

        $actor = $this->actors->current();
        $digest = PayloadDigest::of('reactions.remove', $command->toDigestPayload());
        $namespace = new CommandNamespace($actor->installation, $command->surface->panel, $command->integration, 'reactions.remove');
        $envelope = new CommandEnvelope(
            key: $command->key,
            namespace: $namespace,
            digest: $digest,
        );

        return $this->envelope->run(
            envelope: $envelope,
            surface: $command->surface,
            action: 'sockit.reactions.remove',
            preTransactionChecks: function (ActorContext $verifiedActor) use ($command, $surfaceDef): void {
                $this->authorizer->decide(new ActionRequest(
                    actor: $verifiedActor,
                    action: 'sockit.reactions.remove',
                    surface: $command->surface,
                ))->requireAllowed();

                $this->resolveVisibleTarget($verifiedActor, $command->surface, $command->target, $surfaceDef);
            },
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($command, $namespace): array {
                $actorRef = $verifiedActor->actor ?? throw OperationException::audienceDenied('actor.anonymous');
                $this->reverifyTargetInsideTx($verifiedActor, $command->surface, $command->target);

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
                        'aggregate_id' => $command->target->toWire(),
                        'aggregate_revision' => $revision->value,
                        'event_type' => 'sockit.reactions.removed',
                        'payload' => [
                            'panel' => $command->surface->panel->value,
                            'target' => $command->target->toWire(),
                            'reaction_type' => $command->reactionType,
                        ],
                    ] : null,
                ];
            },
        );
    }

    #[Override]
    public function counts(SurfaceRef $surface, HostEntityRef $target): ReactionCountsView
    {
        [, $surfaceDef] = $this->assertReactionsEnabled($surface);
        $actor = $this->actors->current();

        $this->resolveVisibleTarget($actor, $surface, $target, $surfaceDef);

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

        return $connection->transaction(function () use ($connection, $reactionsTable, $countsTable, $panel): int {
            $connection->table($countsTable)->where('panel_id', $panel->value)->delete();

            $rows = $connection->table($reactionsTable)
                ->selectRaw('panel_id, surface_instance_id, target_type, target_key, reaction_type, COUNT(*) as agg_count')
                ->where('panel_id', $panel->value)
                ->groupBy(['panel_id', 'surface_instance_id', 'target_type', 'target_key', 'reaction_type'])
                ->get();

            $written = 0;
            foreach ($rows as $row) {
                $connection->table($countsTable)->insert([
                    'panel_id' => SockitRows::str($row, 'panel_id'),
                    'surface_instance_id' => SockitRows::str($row, 'surface_instance_id'),
                    'target_type' => SockitRows::str($row, 'target_type'),
                    'target_key' => SockitRows::str($row, 'target_key'),
                    'reaction_type' => SockitRows::str($row, 'reaction_type'),
                    'count' => SockitRows::int($row, 'agg_count'),
                    'source_revision' => 1,
                ]);
                $written++;
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
    ): TargetResolution {
        if ($this->surfacePolicy instanceof SurfaceAudiencePolicy) {
            $admit = $this->surfacePolicy->admit($actor, $surface);
            if (! $admit->canRead()) {
                throw OperationException::audienceDenied($admit->reason);
            }
        }

        $resolution = null;
        foreach ($this->targetResolvers as $resolver) {
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
            $verdict = $this->audienceGate->read($actor, new AudienceSubject(
                key: $target->toWire(),
                spec: $resolution->audience,
                author: $resolution->owner,
                surface: $surface,
                installation: $actor->installation,
                surfaceArchived: false,
                archiveReaders: $surfaceDef->archiveReaders,
            ));

            if (! $verdict->canRead()) {
                throw OperationException::audienceDenied($verdict->reason);
            }
        }

        return $resolution;
    }

    private function reverifyTargetInsideTx(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): void
    {
        $resolution = null;
        foreach ($this->targetResolvers as $resolver) {
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
            $initialCount = max(0, $delta);
            $connection->table($countsTable)->insert([
                'panel_id' => $panelId,
                'surface_instance_id' => $surfaceInstanceId,
                'target_type' => $target->type,
                'target_key' => $target->value,
                'reaction_type' => $reactionType,
                'count' => $initialCount,
                'source_revision' => 1,
            ]);

            return new Revision(1);
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
}
