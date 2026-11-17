<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use JsonException;
use Override;
use Packman\Attributes\Internal;
use RuntimeException;
use SocKit\Actors\Data\ActorContext;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;
use SocKit\Surfaces\Exceptions\SurfaceException;

/**
 * Authoritative P2 operation envelope implementing fresh actor resolution, pre-transaction guards,
 * PostgreSQL savepoint-safe receipt claim, surface incarnation verification, replay lifecycle guards,
 * and atomic outbox insert (including safety-held visibility invalidations).
 */
#[Internal]
final readonly class DatabaseOperationEnvelope implements OperationEnvelopeRunner
{
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SurfaceDefinitions $surfaces,
        private SockitRuntime $runtime,
    ) {}

    /**
     * @param  Closure(ActorContext): void  $preTransactionChecks
     * @param  Closure(ActorContext, Connection): array{result: OperationResult, event?: array{aggregate_type: string, aggregate_id: string, aggregate_revision: int, event_type: string, payload: array<string, mixed>}|null}  $mutation
     * @param  (Closure(ActorContext, Connection, OperationResult): void)|null  $replayChecks
     */
    #[Override]
    public function run(
        CommandEnvelope $envelope,
        SurfaceRef $surface,
        string $action,
        Closure $preTransactionChecks,
        Closure $mutation,
        ?Closure $replayChecks = null,
    ): OperationResult {
        $actor = $this->runtime->actors()->current();

        if (! $envelope->namespace->installation->equals($actor->installation)) {
            throw PanelException::bindingMismatch(
                $surface->panel->value,
                'CommandNamespace installation does not match current ActorContext installation.',
            );
        }

        if (! $envelope->namespace->panel->equals($surface->panel)) {
            throw PanelException::bindingMismatch(
                $surface->panel->value,
                'CommandNamespace panel does not match target SurfaceRef panel.',
            );
        }

        $panelDef = $this->panels->get($surface->panel);
        $surfaceDef = $this->surfaces->get($surface->panel, $surface->kind);

        if (! $panelDef->acceptsHostType($surface->host->type) || $surfaceDef->hostType !== $surface->host->type) {
            throw PanelException::bindingMismatch(
                $surface->panel->value,
                "Surface [{$surfaceDef->key()}] host type [{$surface->host->type}] does not match compiled definition [{$surfaceDef->hostType}].",
            );
        }

        $preTransactionChecks($actor);

        $connection = $this->tables->connection($surface->panel);
        $receiptsTable = $this->tables->table('command_receipts', $surface->panel);
        $surfacesTable = $this->tables->table('surface_instances', $surface->panel);
        $outboxTable = $this->tables->table('outbox_events', $surface->panel);

        return $connection->transaction(function () use (
            $connection,
            $receiptsTable,
            $surfacesTable,
            $outboxTable,
            $envelope,
            $surface,
            $actor,
            $mutation,
            $replayChecks,
        ): OperationResult {
            $receiptId = $this->claimReceiptOrReplay($connection, $receiptsTable, $envelope, $surface, $replay);

            if ($replay instanceof OperationResult) {
                $this->lockAndVerifySurface($connection, $surfacesTable, $surface);

                if ($replayChecks instanceof Closure) {
                    $replayChecks($actor, $connection, $replay);
                } elseif ($replay->resource instanceof ResourceRef) {
                    $this->assertNotTombstoned($connection, $surface, $replay->resource);
                }

                return $replay;
            }

            $this->lockAndVerifySurface($connection, $surfacesTable, $surface);

            $outcome = $mutation($actor, $connection);
            $result = $outcome['result'];

            $completedAt = $this->nowIso();
            $encodedResult = $this->encodeJson($result->toWire());

            $connection->table($receiptsTable)
                ->where('id', $receiptId)
                ->update([
                    'status' => $result->outcome->value,
                    'resource_kind' => $result->resource?->kind->value,
                    'resource_id' => $result->resource?->id,
                    'applied_revision' => $result->revision?->value,
                    'problem_code' => $result->problem?->value,
                    'result_payload' => $encodedResult,
                    'completed_at' => $completedAt,
                ]);

            $event = $outcome['event'] ?? null;

            if ($event !== null) {
                $digest = PayloadDigest::of($event['event_type'], $event['payload'])->value;
                $connection->table($outboxTable)->insert([
                    'id' => Ids::uuid7(),
                    'panel_id' => $surface->panel->value,
                    'aggregate_type' => $event['aggregate_type'],
                    'aggregate_id' => $event['aggregate_id'],
                    'aggregate_revision' => $event['aggregate_revision'],
                    'event_type' => $event['event_type'],
                    'event_schema_version' => 1,
                    'payload_digest' => $digest,
                    'payload' => $this->encodeJson($event['payload']),
                    'occurred_at' => $completedAt,
                    'available_at' => $completedAt,
                    'attempts' => 0,
                    'lease_owner' => null,
                    'lease_until' => null,
                    'delivered_at' => null,
                    'projection_lease_owner' => null,
                    'projection_lease_until' => null,
                    'projection_replayed_at' => null,
                    'last_problem_code' => null,
                ]);
            }

            return $result;
        });
    }

    private function assertNotTombstoned(Connection $connection, SurfaceRef $surface, ResourceRef $resource): void
    {
        $tombstone = $connection->table($this->tables->table('resource_tombstones', $surface->panel))
            ->where('panel_id', $surface->panel->value)
            ->where('resource_kind', $resource->kind->value)
            ->where('resource_id', $resource->id)
            ->first();

        if (! is_object($tombstone)) {
            return;
        }

        if (SockitRows::optStr($tombstone, 'erased_at') !== null) {
            throw OperationException::contentErased($resource->id);
        }

        throw OperationException::contentDeleted($resource->id);
    }

    private function claimReceiptOrReplay(
        Connection $connection,
        string $receiptsTable,
        CommandEnvelope $envelope,
        SurfaceRef $surface,
        ?OperationResult &$replay,
    ): string {
        $replay = null;
        $receiptId = Ids::uuid7();
        $now = $this->nowIso();
        $pendingPayload = $this->encodeJson(OperationResult::pending($receiptId)->toWire());

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $connection->transaction(static function () use (
                    $connection,
                    $receiptsTable,
                    $receiptId,
                    $surface,
                    $envelope,
                    $pendingPayload,
                    $now,
                ): void {
                    $connection->table($receiptsTable)->insert([
                        'id' => $receiptId,
                        'panel_id' => $surface->panel->value,
                        'command_namespace' => $envelope->namespace->toWire(),
                        'command_key' => $envelope->key->value,
                        'command_type' => $envelope->namespace->commandType,
                        'payload_digest' => $envelope->digest->value,
                        'status' => OperationOutcome::Pending->value,
                        'resource_kind' => null,
                        'resource_id' => null,
                        'applied_revision' => null,
                        'problem_code' => null,
                        'result_payload' => $pendingPayload,
                        'created_at' => $now,
                        'completed_at' => null,
                    ]);
                });

                return $receiptId;
            } catch (QueryException $exception) {
                $sqlState = $exception->errorInfo[0] ?? null;

                if (! in_array($sqlState, ['23505', '23000'], true)) {
                    throw $exception;
                }
            }

            $existingQuery = $connection->table($receiptsTable)
                ->where('panel_id', $surface->panel->value)
                ->where('command_namespace', $envelope->namespace->toWire())
                ->where('command_key', $envelope->key->value);

            if ($connection->getDriverName() !== 'sqlite') {
                $existingQuery->lockForUpdate();
            }

            $existing = $existingQuery->first();

            if (! is_object($existing)) {
                continue;
            }

            if (! hash_equals(SockitRows::str($existing, 'payload_digest'), $envelope->digest->value)) {
                throw OperationException::idempotencyConflict(hash('sha256', $envelope->key->value));
            }

            $decoded = SockitRows::jsonArray($existing, 'result_payload');
            $replay = OperationResult::fromWire($decoded);

            return SockitRows::str($existing, 'id');
        }

        throw OperationException::idempotencyConflict(hash('sha256', $envelope->key->value));
    }

    private function lockAndVerifySurface(Connection $connection, string $surfacesTable, SurfaceRef $surface): void
    {
        $query = $connection->table($surfacesTable)
            ->where('panel_id', $surface->panel->value)
            ->where('kind', $surface->kind->value)
            ->where('host_entity_type', $surface->host->type)
            ->where('host_entity_value', $surface->host->value)
            ->orderByDesc('incarnation');

        if ($connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if (! is_object($row)) {
            if ($surface->incarnation->value !== 1) {
                throw SurfaceException::incarnationMismatch(0, $surface->incarnation->value);
            }

            try {
                $connection->transaction(function () use ($connection, $surfacesTable, $surface): void {
                    $connection->table($surfacesTable)->insert([
                        'id' => Ids::uuid7(),
                        'panel_id' => $surface->panel->value,
                        'kind' => $surface->kind->value,
                        'host_entity_type' => $surface->host->type,
                        'host_entity_value' => $surface->host->value,
                        'incarnation' => 1,
                        'lifecycle_state' => SurfaceLifecycleState::Active->value,
                        'created_at' => $this->nowIso(),
                        'archived_at' => null,
                    ]);
                });

                return;
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

        if (SockitRows::str($row, 'lifecycle_state') === SurfaceLifecycleState::Archived->value) {
            throw SurfaceException::archived($surface->panel->value.'/'.$surface->kind->value);
        }

        $activeIncarnation = SockitRows::int($row, 'incarnation');

        if ($activeIncarnation !== $surface->incarnation->value) {
            throw SurfaceException::incarnationMismatch($activeIncarnation, $surface->incarnation->value);
        }
    }

    /** @param array<array-key, mixed> $payload */
    private function encodeJson(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to serialize receipt/outbox JSON: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function nowIso(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
