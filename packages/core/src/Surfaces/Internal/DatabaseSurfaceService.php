<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Override;
use Packman\Attributes\Internal;
use SocKit\Actors\ActorContexts;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
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
 * Panel-isolated runtime surface instance manager supporting idempotent creation,
 * archival, and monotonic incarnation transitions.
 */
#[Internal]
final readonly class DatabaseSurfaceService implements SurfaceService
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private ActorContexts $actors,
    ) {}

    #[Override]
    public function ensureInstance(
        CommandKey $commandKey,
        PanelId $panel,
        SurfaceKindId $kind,
        HostEntityRef $host,
        string $integration = 'sockit.core',
    ): SurfaceInstance {
        $actor = $this->actors->current();
        $this->assertDefinitionAllowsHost($panel, $kind, $host);

        $connection = $this->tables->connection($panel);
        $table = $this->tables->table('surface_instances', $panel);
        $receipts = $this->tables->table('command_receipts', $panel);
        $namespace = new CommandNamespace($actor->installation, $panel, $integration, 'surface.ensure');
        $digest = PayloadDigest::of('surface.ensure', [
            'panel' => $panel->value,
            'kind' => $kind->value,
            'host' => $host->toWire(),
        ]);

        return $connection->transaction(function () use ($connection, $table, $receipts, $panel, $kind, $host, $namespace, $commandKey, $digest): SurfaceInstance {
            $existingReceipt = $this->claimOrFindReceipt($connection, $receipts, $panel->value, $namespace->toWire(), $commandKey->value, 'surface.ensure', $digest);

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

            if ($existingReceipt !== null && is_object($row)) {
                return $this->hydrate($row);
            }

            if (is_object($row)) {
                $instance = $this->hydrate($row);
                $this->completeReceipt($connection, $receipts, $panel->value, $namespace->toWire(), $commandKey->value, $instance);

                return $instance;
            }

            $now = $this->nowIso();
            $id = Ids::uuid7();
            $connection->table($table)->insert([
                'id' => $id,
                'panel_id' => $panel->value,
                'kind' => $kind->value,
                'host_entity_type' => $host->type,
                'host_entity_value' => $host->value,
                'incarnation' => 1,
                'lifecycle_state' => SurfaceLifecycleState::Active->value,
                'created_at' => $now,
                'archived_at' => null,
            ]);

            $instance = new SurfaceInstance(
                id: $id,
                panel: $panel,
                kind: $kind,
                host: $host,
                incarnation: Incarnation::initial(),
                lifecycleState: SurfaceLifecycleState::Active,
                createdAt: new DateTimeImmutable($now),
            );
            $this->completeReceipt($connection, $receipts, $panel->value, $namespace->toWire(), $commandKey->value, $instance);

            return $instance;
        });
    }

    #[Override]
    public function get(SurfaceRef $ref): SurfaceInstance
    {
        $this->panels->get($ref->panel);
        $this->surfaces->get($ref->panel, $ref->kind);

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
        $this->panels->get($panel);
        $this->surfaces->get($panel, $kind);

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
    public function archive(CommandKey $commandKey, SurfaceRef $ref, string $integration = 'sockit.core'): SurfaceInstance
    {
        $actor = $this->actors->current();
        $this->assertDefinitionAllowsHost($ref->panel, $ref->kind, $ref->host);

        $connection = $this->tables->connection($ref->panel);
        $table = $this->tables->table('surface_instances', $ref->panel);
        $receipts = $this->tables->table('command_receipts', $ref->panel);
        $namespace = new CommandNamespace($actor->installation, $ref->panel, $integration, 'surface.archive');
        $digest = PayloadDigest::of('surface.archive', ['surface' => $ref->toWire()]);

        return $connection->transaction(function () use ($connection, $table, $receipts, $ref, $namespace, $commandKey, $digest): SurfaceInstance {
            $existingReceipt = $this->claimOrFindReceipt($connection, $receipts, $ref->panel->value, $namespace->toWire(), $commandKey->value, 'surface.archive', $digest);

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
                throw SurfaceException::notFound($ref->panel->value.'/'.$ref->kind->value);
            }

            $instance = $this->hydrate($row);

            if ($existingReceipt !== null || $instance->isArchived()) {
                if ($existingReceipt === null) {
                    $this->completeReceipt($connection, $receipts, $ref->panel->value, $namespace->toWire(), $commandKey->value, $instance);
                }

                return $instance;
            }

            $archivedAt = $this->nowIso();
            $connection->table($table)
                ->where('id', $instance->id)
                ->update([
                    'lifecycle_state' => SurfaceLifecycleState::Archived->value,
                    'archived_at' => $archivedAt,
                ]);

            $archived = new SurfaceInstance(
                id: $instance->id,
                panel: $instance->panel,
                kind: $instance->kind,
                host: $instance->host,
                incarnation: $instance->incarnation,
                lifecycleState: SurfaceLifecycleState::Archived,
                createdAt: $instance->createdAt,
                archivedAt: new DateTimeImmutable($archivedAt),
            );
            $this->completeReceipt($connection, $receipts, $ref->panel->value, $namespace->toWire(), $commandKey->value, $archived);

            return $archived;
        });
    }

    #[Override]
    public function startNewIncarnation(
        CommandKey $commandKey,
        PanelId $panel,
        SurfaceKindId $kind,
        HostEntityRef $host,
        string $integration = 'sockit.core',
    ): SurfaceInstance {
        $actor = $this->actors->current();
        $this->assertDefinitionAllowsHost($panel, $kind, $host);

        $connection = $this->tables->connection($panel);
        $table = $this->tables->table('surface_instances', $panel);
        $receipts = $this->tables->table('command_receipts', $panel);
        $namespace = new CommandNamespace($actor->installation, $panel, $integration, 'surface.reincarnate');
        $digest = PayloadDigest::of('surface.reincarnate', [
            'panel' => $panel->value,
            'kind' => $kind->value,
            'host' => $host->toWire(),
        ]);

        return $connection->transaction(function () use ($connection, $table, $receipts, $panel, $kind, $host, $namespace, $commandKey, $digest): SurfaceInstance {
            $existingReceipt = $this->claimOrFindReceipt($connection, $receipts, $panel->value, $namespace->toWire(), $commandKey->value, 'surface.reincarnate', $digest);

            if ($existingReceipt !== null && isset($existingReceipt['resource_id']) && is_string($existingReceipt['resource_id'])) {
                $replayed = $connection->table($table)->where('id', $existingReceipt['resource_id'])->first();
                if (is_object($replayed)) {
                    return $this->hydrate($replayed);
                }
            }

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
                'created_at' => $now,
                'archived_at' => null,
            ]);

            $created = new SurfaceInstance(
                id: $id,
                panel: $panel,
                kind: $kind,
                host: $host,
                incarnation: new Incarnation($nextIncarnation),
                lifecycleState: SurfaceLifecycleState::Active,
                createdAt: new DateTimeImmutable($now),
            );
            $this->completeReceipt($connection, $receipts, $panel->value, $namespace->toWire(), $commandKey->value, $created);

            return $created;
        });
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

    /** @return ?array<string, mixed> */
    private function claimOrFindReceipt(
        Connection $connection,
        string $receiptsTable,
        string $panelId,
        string $namespace,
        string $key,
        string $commandType,
        PayloadDigest $digest,
    ): ?array {
        $receiptId = Ids::uuid7();
        $pending = OperationResult::pending($receiptId)->toWire();

        try {
            $connection->transaction(static function () use ($connection, $receiptsTable, $receiptId, $panelId, $namespace, $key, $commandType, $digest, $pending): void {
                $connection->table($receiptsTable)->insert([
                    'id' => $receiptId,
                    'panel_id' => $panelId,
                    'command_namespace' => $namespace,
                    'command_key' => $key,
                    'command_type' => $commandType,
                    'payload_digest' => $digest->value,
                    'status' => 'pending',
                    'resource_kind' => null,
                    'resource_id' => null,
                    'applied_revision' => null,
                    'problem_code' => null,
                    'result_payload' => json_encode($pending, JSON_THROW_ON_ERROR),
                    'created_at' => gmdate('Y-m-d H:i:s.uP'),
                    'completed_at' => null,
                ]);
            });

            return null;
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            if (! in_array($sqlState, ['23505', '23000'], true)) {
                throw $exception;
            }
        }

        $row = $connection->table($receiptsTable)
            ->where('panel_id', $panelId)
            ->where('command_namespace', $namespace)
            ->where('command_key', $key)
            ->first();

        if (! is_object($row)) {
            throw SurfaceException::invalidDefinition('RECEIPT_MISSING', 'Concurrent receipt claim disappeared before replay.');
        }

        if (! hash_equals(SockitRows::str($row, 'payload_digest'), $digest->value)) {
            throw OperationException::idempotencyConflict(hash('sha256', $key));
        }

        return [
            'id' => SockitRows::str($row, 'id'),
            'resource_id' => SockitRows::optStr($row, 'resource_id'),
        ];
    }

    private function completeReceipt(
        Connection $connection,
        string $receiptsTable,
        string $panelId,
        string $namespace,
        string $key,
        SurfaceInstance $instance,
    ): void {
        $row = $connection->table($receiptsTable)
            ->where('panel_id', $panelId)
            ->where('command_namespace', $namespace)
            ->where('command_key', $key)
            ->first();

        $receiptId = is_object($row) ? SockitRows::str($row, 'id') : Ids::uuid7();
        $result = OperationResult::confirmed(
            $receiptId,
            new ResourceRef($instance->panel, ResourceKind::SurfaceInstance, $instance->id),
            new Revision($instance->incarnation->value),
        );

        $connection->table($receiptsTable)
            ->where('id', $receiptId)
            ->update([
                'status' => 'confirmed',
                'resource_kind' => ResourceKind::SurfaceInstance->value,
                'resource_id' => $instance->id,
                'applied_revision' => $instance->incarnation->value,
                'result_payload' => json_encode($result->toWire(), JSON_THROW_ON_ERROR),
                'completed_at' => $this->nowIso(),
            ]);
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
        );
    }

    private function nowIso(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
