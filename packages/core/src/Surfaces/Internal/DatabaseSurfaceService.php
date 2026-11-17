<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceInstance;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;
use SocKit\Surfaces\Exceptions\SurfaceException;

/**
 * Panel-isolated runtime surface instance manager routing every lifecycle mutation through
 * {@see DatabaseOperationEnvelope} with CAS revision checks, non-resurrection tombstones,
 * atomic outbox emission, and monotonic incarnation transitions.
 */
#[Internal]
final readonly class DatabaseSurfaceService implements SurfaceService
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private SockitRuntime $runtime,
    ) {}

    #[Override]
    public function ensureInstance(
        CommandKey $commandKey,
        PanelId $panel,
        SurfaceKindId $kind,
        HostEntityRef $host,
        string $integration = 'sockit.core',
    ): SurfaceInstance {
        $this->assertDefinitionAllowsHost($panel, $kind, $host);
        $actor = $this->runtime->actors()->current();
        $ref = new SurfaceRef($panel, $kind, $host, Incarnation::initial());
        $namespace = new CommandNamespace($actor->installation, $panel, $integration, 'surface.ensure');
        $digest = PayloadDigest::of('surface.ensure', [
            'panel' => $panel->value,
            'kind' => $kind->value,
            'host' => $host->toWire(),
        ]);

        $envelope = new CommandEnvelope(
            key: $commandKey,
            namespace: $namespace,
            digest: $digest,
        );

        $result = $this->runtime->surfaceLifecycleEnvelope()->runSurfaceLifecycle(
            envelope: $envelope,
            surface: $ref,
            action: 'sockit.surface.ensure',
            preTransactionChecks: static function (ActorContext $verifiedActor): void {},
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($panel, $kind, $host, $namespace, $commandKey): array {
                $table = $this->tables->table('surface_instances', $panel);
                $query = $connection->table($table)
                    ->where('panel_id', $panel->value)
                    ->where('kind', $kind->value)
                    ->where('host_entity_type', $host->type)
                    ->where('host_entity_value', $host->value)
                    ->orderByDesc('incarnation');

                if ($connection->getDriverName() !== 'sqlite') {
                    $query->lockForUpdate();
                }

                $row = $query->first();
                $receiptId = $this->findReceiptId($connection, $panel, $namespace->toWire(), $commandKey->value);

                if (is_object($row)) {
                    $existing = $this->hydrate($row);

                    if ($existing->isDeleted()) {
                        throw SurfaceException::deleted($panel->value.'/'.$kind->value);
                    }

                    return [
                        'result' => OperationResult::confirmed(
                            $receiptId,
                            new ResourceRef($panel, ResourceKind::SurfaceInstance, $existing->id),
                            $existing->revision,
                        ),
                    ];
                }

                $now = $this->nowIso();
                $id = Ids::uuid7();

                try {
                    $connection->transaction(static function () use ($connection, $table, $id, $panel, $kind, $host, $now): void {
                        $connection->table($table)->insert([
                            'id' => $id,
                            'panel_id' => $panel->value,
                            'kind' => $kind->value,
                            'host_entity_type' => $host->type,
                            'host_entity_value' => $host->value,
                            'incarnation' => 1,
                            'lifecycle_state' => SurfaceLifecycleState::Active->value,
                            'revision' => 1,
                            'created_at' => $now,
                            'archived_at' => null,
                            'deleted_at' => null,
                        ]);
                    });
                } catch (QueryException $exception) {
                    $sqlState = $exception->errorInfo[0] ?? null;

                    if (! in_array($sqlState, ['23505', '23000'], true)) {
                        throw $exception;
                    }

                    $winnerRow = $query->first();

                    if (! is_object($winnerRow)) {
                        throw $exception;
                    }

                    $winner = $this->hydrate($winnerRow);

                    if ($winner->isDeleted()) {
                        throw SurfaceException::deleted($panel->value.'/'.$kind->value);
                    }

                    return [
                        'result' => OperationResult::confirmed(
                            $receiptId,
                            new ResourceRef($panel, ResourceKind::SurfaceInstance, $winner->id),
                            $winner->revision,
                        ),
                    ];
                }

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($panel, ResourceKind::SurfaceInstance, $id),
                        new Revision(1),
                    ),
                    'event' => [
                        'aggregate_type' => 'surface_instance',
                        'aggregate_id' => $id,
                        'aggregate_revision' => 1,
                        'event_type' => 'sockit.surfaces.created',
                        'payload' => [
                            'panel' => $panel->value,
                            'surface_id' => $id,
                            'surface' => new SurfaceRef($panel, $kind, $host, Incarnation::initial())->toWire(),
                            'incarnation' => 1,
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($panel): void {
                if (! $replay->resource instanceof ResourceRef) {
                    return;
                }

                $row = $this->loadById($connection, $panel, $replay->resource->id);
                if (! is_object($row)) {
                    throw SurfaceException::notFound($replay->resource->id);
                }

                if ($this->hydrate($row)->isDeleted()) {
                    throw SurfaceException::deleted($replay->resource->id);
                }
            },
        );

        return $this->loadSurfaceFromResult($panel, $result);
    }

    #[Override]
    public function create(
        CommandKey $commandKey,
        SurfaceRef $ref,
        string $integration = 'sockit.core',
    ): SurfaceInstance {
        $this->assertDefinitionAllowsHost($ref->panel, $ref->kind, $ref->host);

        if ($ref->incarnation->value !== 1) {
            throw SurfaceException::incarnationMismatch(1, $ref->incarnation->value);
        }

        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $ref->panel, $integration, 'surface.create');
        $digest = PayloadDigest::of('surface.create', ['surface' => $ref->toWire()]);

        $envelope = new CommandEnvelope(
            key: $commandKey,
            namespace: $namespace,
            digest: $digest,
        );

        $result = $this->runtime->surfaceLifecycleEnvelope()->runSurfaceLifecycle(
            envelope: $envelope,
            surface: $ref,
            action: 'sockit.surface.create',
            preTransactionChecks: static function (ActorContext $verifiedActor): void {},
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($ref, $namespace, $commandKey): array {
                $table = $this->tables->table('surface_instances', $ref->panel);
                $query = $connection->table($table)
                    ->where('panel_id', $ref->panel->value)
                    ->where('kind', $ref->kind->value)
                    ->where('host_entity_type', $ref->host->type)
                    ->where('host_entity_value', $ref->host->value)
                    ->where('incarnation', $ref->incarnation->value);

                if ($connection->getDriverName() !== 'sqlite') {
                    $query->lockForUpdate();
                }

                $existing = $query->first();
                if (is_object($existing)) {
                    if (SockitRows::str($existing, 'lifecycle_state') === SurfaceLifecycleState::Deleted->value) {
                        throw SurfaceException::deleted($ref->panel->value.'/'.$ref->kind->value);
                    }

                    throw OperationException::revisionConflict(0, SockitRows::int($existing, 'revision', 1));
                }

                $now = $this->nowIso();
                $id = Ids::uuid7();

                try {
                    $connection->transaction(static function () use ($connection, $table, $id, $ref, $now): void {
                        $connection->table($table)->insert([
                            'id' => $id,
                            'panel_id' => $ref->panel->value,
                            'kind' => $ref->kind->value,
                            'host_entity_type' => $ref->host->type,
                            'host_entity_value' => $ref->host->value,
                            'incarnation' => $ref->incarnation->value,
                            'lifecycle_state' => SurfaceLifecycleState::Active->value,
                            'revision' => 1,
                            'created_at' => $now,
                            'archived_at' => null,
                            'deleted_at' => null,
                        ]);
                    });
                } catch (QueryException $exception) {
                    $sqlState = $exception->errorInfo[0] ?? null;

                    if (in_array($sqlState, ['23505', '23000'], true)) {
                        throw OperationException::revisionConflict(0, 1);
                    }

                    throw $exception;
                }

                $receiptId = $this->findReceiptId($connection, $ref->panel, $namespace->toWire(), $commandKey->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($ref->panel, ResourceKind::SurfaceInstance, $id),
                        new Revision(1),
                    ),
                    'event' => [
                        'aggregate_type' => 'surface_instance',
                        'aggregate_id' => $id,
                        'aggregate_revision' => 1,
                        'event_type' => 'sockit.surfaces.created',
                        'payload' => [
                            'panel' => $ref->panel->value,
                            'surface_id' => $id,
                            'surface' => $ref->toWire(),
                            'incarnation' => $ref->incarnation->value,
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($ref): void {
                if (! $replay->resource instanceof ResourceRef) {
                    return;
                }

                $row = $this->loadById($connection, $ref->panel, $replay->resource->id);
                if (! is_object($row)) {
                    throw SurfaceException::notFound($ref->panel->value.'/'.$ref->kind->value);
                }

                if ($this->hydrate($row)->isDeleted()) {
                    throw SurfaceException::deleted($ref->panel->value.'/'.$ref->kind->value);
                }
            },
        );

        return $this->loadSurfaceFromResult($ref->panel, $result);
    }

    #[Override]
    public function get(SurfaceRef $ref): SurfaceInstance
    {
        $this->assertDefinitionAllowsHost($ref->panel, $ref->kind, $ref->host);

        $viewer = $this->runtime->actors()->current();
        $admission = $this->runtime->audienceGate()->admitForRead($viewer, $ref);
        if (! $admission->canRead()) {
            throw OperationException::audienceDenied($admission->reason);
        }

        $connection = $this->tables->connection($ref->panel);
        $table = $this->tables->table('surface_instances', $ref->panel);

        $row = $connection->table($table)
            ->where('panel_id', $ref->panel->value)
            ->where('kind', $ref->kind->value)
            ->where('host_entity_type', $ref->host->type)
            ->where('host_entity_value', $ref->host->value)
            ->where('incarnation', $ref->incarnation->value)
            ->first();

        if (! is_object($row)) {
            throw SurfaceException::notFound($ref->panel->value.'/'.$ref->kind->value.'#'.$ref->incarnation->value);
        }

        return $this->hydrate($row);
    }

    #[Override]
    public function findActive(PanelId $panel, SurfaceKindId $kind, HostEntityRef $host): ?SurfaceInstance
    {
        $this->assertDefinitionAllowsHost($panel, $kind, $host);

        $viewer = $this->runtime->actors()->current();
        $probe = new SurfaceRef($panel, $kind, $host, Incarnation::initial());
        $admission = $this->runtime->audienceGate()->admitForRead($viewer, $probe);
        if (! $admission->canRead()) {
            throw OperationException::audienceDenied($admission->reason);
        }

        $connection = $this->tables->connection($panel);
        $table = $this->tables->table('surface_instances', $panel);

        $row = $connection->table($table)
            ->where('panel_id', $panel->value)
            ->where('kind', $kind->value)
            ->where('host_entity_type', $host->type)
            ->where('host_entity_value', $host->value)
            ->where('lifecycle_state', SurfaceLifecycleState::Active->value)
            ->orderByDesc('incarnation')
            ->first();

        return is_object($row) ? $this->hydrate($row) : null;
    }

    #[Override]
    public function archive(
        CommandKey $commandKey,
        SurfaceRef $ref,
        Revision $expectedRevision,
        string $integration = 'sockit.core',
    ): SurfaceInstance {
        $this->assertDefinitionAllowsHost($ref->panel, $ref->kind, $ref->host);
        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $ref->panel, $integration, 'surface.archive');
        $digest = PayloadDigest::of('surface.archive', [
            'surface' => $ref->toWire(),
            'expected_revision' => $expectedRevision->value,
        ]);

        $envelope = new CommandEnvelope(
            key: $commandKey,
            namespace: $namespace,
            digest: $digest,
            expectedRevision: $expectedRevision,
        );

        $result = $this->runtime->surfaceLifecycleEnvelope()->runSurfaceLifecycle(
            envelope: $envelope,
            surface: $ref,
            action: 'sockit.surface.archive',
            preTransactionChecks: static function (ActorContext $verifiedActor): void {},
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($ref, $expectedRevision, $namespace, $commandKey): array {
                $table = $this->tables->table('surface_instances', $ref->panel);
                $row = $this->lockByRef($connection, $table, $ref);
                $instance = $this->hydrate($row);

                if ($instance->isDeleted()) {
                    throw SurfaceException::deleted($ref->panel->value.'/'.$ref->kind->value);
                }

                if ($instance->revision->value !== $expectedRevision->value) {
                    throw OperationException::revisionConflict($expectedRevision->value, $instance->revision->value);
                }

                if ($instance->isArchived()) {
                    throw SurfaceException::archived($ref->panel->value.'/'.$ref->kind->value);
                }

                $now = $this->nowIso();
                $nextRevision = $instance->revision->value + 1;

                $affected = $connection->table($table)
                    ->where('id', $instance->id)
                    ->where('panel_id', $ref->panel->value)
                    ->where('revision', $expectedRevision->value)
                    ->update([
                        'lifecycle_state' => SurfaceLifecycleState::Archived->value,
                        'archived_at' => $now,
                        'revision' => $nextRevision,
                    ]);

                if ($affected !== 1) {
                    throw OperationException::revisionConflict($expectedRevision->value, $instance->revision->value);
                }

                $receiptId = $this->findReceiptId($connection, $ref->panel, $namespace->toWire(), $commandKey->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($ref->panel, ResourceKind::SurfaceInstance, $instance->id),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'surface_instance',
                        'aggregate_id' => $instance->id,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.surfaces.archived',
                        'payload' => [
                            'panel' => $ref->panel->value,
                            'surface_id' => $instance->id,
                            'surface' => $ref->toWire(),
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($ref): void {
                if (! $replay->resource instanceof ResourceRef) {
                    return;
                }

                $row = $this->loadById($connection, $ref->panel, $replay->resource->id);
                if (! is_object($row)) {
                    throw SurfaceException::notFound($ref->panel->value.'/'.$ref->kind->value);
                }

                if ($this->hydrate($row)->isDeleted()) {
                    throw SurfaceException::deleted($ref->panel->value.'/'.$ref->kind->value);
                }
            },
        );

        return $this->loadSurfaceFromResult($ref->panel, $result);
    }

    #[Override]
    public function resume(
        CommandKey $commandKey,
        SurfaceRef $ref,
        Revision $expectedRevision,
        string $integration = 'sockit.core',
    ): SurfaceInstance {
        $this->assertDefinitionAllowsHost($ref->panel, $ref->kind, $ref->host);
        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $ref->panel, $integration, 'surface.resume');
        $digest = PayloadDigest::of('surface.resume', [
            'surface' => $ref->toWire(),
            'expected_revision' => $expectedRevision->value,
        ]);

        $envelope = new CommandEnvelope(
            key: $commandKey,
            namespace: $namespace,
            digest: $digest,
            expectedRevision: $expectedRevision,
        );

        $result = $this->runtime->surfaceLifecycleEnvelope()->runSurfaceLifecycle(
            envelope: $envelope,
            surface: $ref,
            action: 'sockit.surface.resume',
            preTransactionChecks: static function (ActorContext $verifiedActor): void {},
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($ref, $expectedRevision, $namespace, $commandKey): array {
                $table = $this->tables->table('surface_instances', $ref->panel);
                $row = $this->lockByRef($connection, $table, $ref);
                $instance = $this->hydrate($row);

                if ($instance->isDeleted()) {
                    throw SurfaceException::deleted($ref->panel->value.'/'.$ref->kind->value);
                }

                if ($instance->revision->value !== $expectedRevision->value) {
                    throw OperationException::revisionConflict($expectedRevision->value, $instance->revision->value);
                }

                if (! $instance->isArchived()) {
                    throw SurfaceException::invalidDefinition('SURFACE_NOT_ARCHIVED', 'Only an archived surface can be resumed.');
                }

                $nextRevision = $instance->revision->value + 1;

                $affected = $connection->table($table)
                    ->where('id', $instance->id)
                    ->where('panel_id', $ref->panel->value)
                    ->where('revision', $expectedRevision->value)
                    ->update([
                        'lifecycle_state' => SurfaceLifecycleState::Active->value,
                        'archived_at' => null,
                        'revision' => $nextRevision,
                    ]);

                if ($affected !== 1) {
                    throw OperationException::revisionConflict($expectedRevision->value, $instance->revision->value);
                }

                $receiptId = $this->findReceiptId($connection, $ref->panel, $namespace->toWire(), $commandKey->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($ref->panel, ResourceKind::SurfaceInstance, $instance->id),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'surface_instance',
                        'aggregate_id' => $instance->id,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.surfaces.resumed',
                        'payload' => [
                            'panel' => $ref->panel->value,
                            'surface_id' => $instance->id,
                            'surface' => $ref->toWire(),
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($ref): void {
                if (! $replay->resource instanceof ResourceRef) {
                    return;
                }

                $row = $this->loadById($connection, $ref->panel, $replay->resource->id);
                if (! is_object($row)) {
                    throw SurfaceException::notFound($ref->panel->value.'/'.$ref->kind->value);
                }

                if ($this->hydrate($row)->isDeleted()) {
                    throw SurfaceException::deleted($ref->panel->value.'/'.$ref->kind->value);
                }
            },
            allowReadOnlySurfaceAdmission: true,
        );

        return $this->loadSurfaceFromResult($ref->panel, $result);
    }

    #[Override]
    public function delete(
        CommandKey $commandKey,
        SurfaceRef $ref,
        Revision $expectedRevision,
        string $integration = 'sockit.core',
    ): SurfaceInstance {
        $this->assertDefinitionAllowsHost($ref->panel, $ref->kind, $ref->host);
        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $ref->panel, $integration, 'surface.delete');
        $digest = PayloadDigest::of('surface.delete', [
            'surface' => $ref->toWire(),
            'expected_revision' => $expectedRevision->value,
        ]);

        $envelope = new CommandEnvelope(
            key: $commandKey,
            namespace: $namespace,
            digest: $digest,
            expectedRevision: $expectedRevision,
        );

        $result = $this->runtime->surfaceLifecycleEnvelope()->runSurfaceLifecycle(
            envelope: $envelope,
            surface: $ref,
            action: 'sockit.surface.delete',
            preTransactionChecks: static function (ActorContext $verifiedActor): void {},
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($ref, $expectedRevision, $namespace, $commandKey): array {
                $table = $this->tables->table('surface_instances', $ref->panel);
                $row = $this->lockByRef($connection, $table, $ref);
                $instance = $this->hydrate($row);

                if ($instance->isDeleted()) {
                    throw SurfaceException::deleted($ref->panel->value.'/'.$ref->kind->value);
                }

                if ($instance->revision->value !== $expectedRevision->value) {
                    throw OperationException::revisionConflict($expectedRevision->value, $instance->revision->value);
                }

                $now = $this->nowIso();
                $nextRevision = $instance->revision->value + 1;

                $affected = $connection->table($table)
                    ->where('id', $instance->id)
                    ->where('panel_id', $ref->panel->value)
                    ->where('revision', $expectedRevision->value)
                    ->update([
                        'lifecycle_state' => SurfaceLifecycleState::Deleted->value,
                        'deleted_at' => $now,
                        'revision' => $nextRevision,
                    ]);

                if ($affected !== 1) {
                    throw OperationException::revisionConflict($expectedRevision->value, $instance->revision->value);
                }

                $tombstoneTable = $this->tables->table('resource_tombstones', $ref->panel);
                try {
                    $connection->transaction(static function () use ($connection, $tombstoneTable, $ref, $instance, $nextRevision, $now): void {
                        $connection->table($tombstoneTable)->insert([
                            'id' => Ids::uuid7(),
                            'panel_id' => $ref->panel->value,
                            'resource_kind' => ResourceKind::SurfaceInstance->value,
                            'resource_id' => $instance->id,
                            'last_revision' => $nextRevision,
                            'incarnation' => $ref->incarnation->value,
                            'deleted_at' => $now,
                            'erased_at' => null,
                        ]);
                    });
                } catch (QueryException $exception) {
                    $sqlState = $exception->errorInfo[0] ?? null;
                    if (! in_array($sqlState, ['23505', '23000'], true)) {
                        throw $exception;
                    }
                }

                $receiptId = $this->findReceiptId($connection, $ref->panel, $namespace->toWire(), $commandKey->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($ref->panel, ResourceKind::SurfaceInstance, $instance->id),
                        new Revision($nextRevision),
                    ),
                    'event' => [
                        'aggregate_type' => 'surface_instance',
                        'aggregate_id' => $instance->id,
                        'aggregate_revision' => $nextRevision,
                        'event_type' => 'sockit.surfaces.deleted',
                        'payload' => [
                            'panel' => $ref->panel->value,
                            'surface_id' => $instance->id,
                            'surface' => $ref->toWire(),
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($ref): void {
                if (! $replay->resource instanceof ResourceRef) {
                    return;
                }

                $row = $this->loadById($connection, $ref->panel, $replay->resource->id);
                if (! is_object($row)) {
                    throw SurfaceException::notFound($ref->panel->value.'/'.$ref->kind->value);
                }
            },
            allowReadOnlySurfaceAdmission: true,
        );

        return $this->loadSurfaceFromResult($ref->panel, $result);
    }

    #[Override]
    public function startNewIncarnation(
        CommandKey $commandKey,
        PanelId $panel,
        SurfaceKindId $kind,
        HostEntityRef $host,
        string $integration = 'sockit.core',
    ): SurfaceInstance {
        $this->assertDefinitionAllowsHost($panel, $kind, $host);
        $actor = $this->runtime->actors()->current();
        $namespace = new CommandNamespace($actor->installation, $panel, $integration, 'surface.reincarnate');
        $digest = PayloadDigest::of('surface.reincarnate', [
            'panel' => $panel->value,
            'kind' => $kind->value,
            'host' => $host->toWire(),
        ]);

        $envelope = new CommandEnvelope(
            key: $commandKey,
            namespace: $namespace,
            digest: $digest,
        );

        $result = $this->runtime->surfaceLifecycleEnvelope()->runSurfaceLifecycle(
            envelope: $envelope,
            surface: new SurfaceRef($panel, $kind, $host, Incarnation::initial()),
            action: 'sockit.surface.reincarnate',
            preTransactionChecks: static function (ActorContext $verifiedActor): void {},
            mutation: function (ActorContext $verifiedActor, Connection $connection) use ($panel, $kind, $host, $namespace, $commandKey): array {
                $table = $this->tables->table('surface_instances', $panel);
                $created = null;

                for ($attempt = 0; $attempt < 3; $attempt++) {
                    try {
                        $created = $connection->transaction(function () use ($connection, $table, $panel, $kind, $host): SurfaceInstance {
                            $latestQuery = $connection->table($table)
                                ->where('panel_id', $panel->value)
                                ->where('kind', $kind->value)
                                ->where('host_entity_type', $host->type)
                                ->where('host_entity_value', $host->value)
                                ->orderByDesc('incarnation');

                            if ($connection->getDriverName() !== 'sqlite') {
                                $latestQuery->lockForUpdate();
                            }

                            $latest = $latestQuery->first();
                            $nextIncarnation = is_object($latest) ? SockitRows::int($latest, 'incarnation') + 1 : 1;
                            $now = $this->nowIso();

                            if (is_object($latest) && SockitRows::str($latest, 'lifecycle_state') === SurfaceLifecycleState::Active->value) {
                                $connection->table($table)->where('id', SockitRows::str($latest, 'id'))->update([
                                    'lifecycle_state' => SurfaceLifecycleState::Archived->value,
                                    'archived_at' => $now,
                                    'revision' => SockitRows::int($latest, 'revision', 1) + 1,
                                ]);
                            }

                            $id = Ids::uuid7();
                            $connection->table($table)->insert([
                                'id' => $id,
                                'panel_id' => $panel->value,
                                'kind' => $kind->value,
                                'host_entity_type' => $host->type,
                                'host_entity_value' => $host->value,
                                'incarnation' => $nextIncarnation,
                                'lifecycle_state' => SurfaceLifecycleState::Active->value,
                                'revision' => 1,
                                'created_at' => $now,
                                'archived_at' => null,
                                'deleted_at' => null,
                            ]);

                            return new SurfaceInstance(
                                id: $id,
                                panel: $panel,
                                kind: $kind,
                                host: $host,
                                incarnation: new Incarnation($nextIncarnation),
                                lifecycleState: SurfaceLifecycleState::Active,
                                createdAt: new DateTimeImmutable($now),
                                revision: new Revision(1),
                            );
                        });

                        break;
                    } catch (QueryException $exception) {
                        $sqlState = $exception->errorInfo[0] ?? null;
                        if ($attempt === 2 || ! in_array($sqlState, ['23505', '23000'], true)) {
                            throw $exception;
                        }
                    }
                }

                if (! $created instanceof SurfaceInstance) {
                    throw SurfaceException::invalidDefinition('SURFACE_RACE', 'Unable to advance surface incarnation under contention.');
                }

                $receiptId = $this->findReceiptId($connection, $panel, $namespace->toWire(), $commandKey->value);

                return [
                    'result' => OperationResult::confirmed(
                        $receiptId,
                        new ResourceRef($panel, ResourceKind::SurfaceInstance, $created->id),
                        $created->revision,
                    ),
                    'event' => [
                        'aggregate_type' => 'surface_instance',
                        'aggregate_id' => $created->id,
                        'aggregate_revision' => $created->revision->value,
                        'event_type' => 'sockit.surfaces.reincarnated',
                        'payload' => [
                            'panel' => $panel->value,
                            'surface_id' => $created->id,
                            'surface' => $created->ref()->toWire(),
                            'incarnation' => $created->incarnation->value,
                        ],
                    ],
                ];
            },
            replayChecks: function (ActorContext $verifiedActor, Connection $connection, OperationResult $replay) use ($panel, $kind, $host): void {
                if (! $replay->resource instanceof ResourceRef) {
                    return;
                }

                $replayedRow = $this->loadById($connection, $panel, $replay->resource->id);
                if (! is_object($replayedRow)) {
                    throw SurfaceException::notFound($panel->value.'/'.$kind->value);
                }

                $replayedInstance = $this->hydrate($replayedRow);
                $latestRow = $connection->table($this->tables->table('surface_instances', $panel))
                    ->where('panel_id', $panel->value)
                    ->where('kind', $kind->value)
                    ->where('host_entity_type', $host->type)
                    ->where('host_entity_value', $host->value)
                    ->orderByDesc('incarnation')
                    ->first();

                if (is_object($latestRow) && SockitRows::int($latestRow, 'incarnation') !== $replayedInstance->incarnation->value) {
                    throw SurfaceException::incarnationMismatch(SockitRows::int($latestRow, 'incarnation'), $replayedInstance->incarnation->value);
                }

                if ($replayedInstance->isDeleted()) {
                    throw SurfaceException::deleted($panel->value.'/'.$kind->value);
                }

                if ($replayedInstance->isArchived()) {
                    throw SurfaceException::archived($panel->value.'/'.$kind->value);
                }
            },
            allowReadOnlySurfaceAdmission: true,
        );

        return $this->loadSurfaceFromResult($panel, $result);
    }

    private function assertDefinitionAllowsHost(PanelId $panel, SurfaceKindId $kind, HostEntityRef $host): void
    {
        $this->panels->get($panel);
        $definition = $this->surfaces->get($panel, $kind);

        if ($definition->hostType !== $host->type) {
            throw SurfaceException::invalidDefinition(
                'SURFACE_HOST_MISMATCH',
                "Surface [{$definition->key()}] requires host type [{$definition->hostType}], got [{$host->type}].",
                ['expected_host_type' => $definition->hostType, 'actual_host_type' => $host->type],
            );
        }
    }

    private function lockByRef(Connection $connection, string $table, SurfaceRef $ref): object
    {
        $query = $connection->table($table)
            ->where('panel_id', $ref->panel->value)
            ->where('kind', $ref->kind->value)
            ->where('host_entity_type', $ref->host->type)
            ->where('host_entity_value', $ref->host->value)
            ->where('incarnation', $ref->incarnation->value);

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! is_object($row)) {
            throw SurfaceException::notFound($ref->panel->value.'/'.$ref->kind->value.'#'.$ref->incarnation->value);
        }

        return $row;
    }

    private function loadById(Connection $connection, PanelId $panel, string $id): ?object
    {
        return $connection->table($this->tables->table('surface_instances', $panel))
            ->where('panel_id', $panel->value)
            ->where('id', $id)
            ->first();
    }

    private function loadSurfaceFromResult(PanelId $panel, OperationResult $result): SurfaceInstance
    {
        if (! $result->resource instanceof ResourceRef) {
            throw SurfaceException::notFound($panel->value);
        }

        $row = $this->loadById($this->tables->connection($panel), $panel, $result->resource->id);
        if (! is_object($row)) {
            throw SurfaceException::notFound($result->resource->id);
        }

        return $this->hydrate($row);
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

    private function hydrate(object $row): SurfaceInstance
    {
        $state = SurfaceLifecycleState::from(SockitRows::str($row, 'lifecycle_state'));

        return new SurfaceInstance(
            id: SockitRows::str($row, 'id'),
            panel: new PanelId(SockitRows::str($row, 'panel_id')),
            kind: new SurfaceKindId(SockitRows::str($row, 'kind')),
            host: new HostEntityRef(SockitRows::str($row, 'host_entity_type'), SockitRows::str($row, 'host_entity_value')),
            incarnation: new Incarnation(SockitRows::int($row, 'incarnation', 1)),
            lifecycleState: $state,
            createdAt: SockitRows::date($row, 'created_at'),
            archivedAt: SockitRows::optDate($row, 'archived_at'),
            revision: new Revision(SockitRows::int($row, 'revision', 1)),
            deletedAt: SockitRows::optDate($row, 'deleted_at'),
        );
    }

    private function nowIso(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
