<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Override;
use Packman\Attributes\Internal;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Data\OutboxEventRecord;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\Ids;
use Throwable;

/**
 * Panel-isolated transactional outbox relay establishing durable `lease_owner`/`lease_until` claims
 * via `SKIP LOCKED` on PostgreSQL and tracking `projection_only` replay independently of normal delivery.
 */
#[Internal]
final readonly class DatabaseOutboxService implements OutboxRelay
{
    private const int LEASE_SECONDS = 60;

    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private SockitRuntime $runtime,
    ) {}

    #[Override]
    public function relayPending(PanelId $panel, int $batchSize = 100, ReplayMode $mode = ReplayMode::Normal): int
    {
        $this->panels->get($panel);

        $connection = $this->tables->connection($panel);
        $table = $this->tables->table('outbox_events', $panel);
        $consumers = $this->runtime->outboxConsumers();
        $leaseOwner = 'worker:'.Ids::uuid7();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s.uP');
        $leaseUntilStr = $now->modify('+'.self::LEASE_SECONDS.' seconds')->format('Y-m-d H:i:s.uP');
        $isProjectionOnly = $mode === ReplayMode::ProjectionOnly;
        $doneColumn = $isProjectionOnly ? 'projection_replayed_at' : 'delivered_at';
        $ownerColumn = $isProjectionOnly ? 'projection_lease_owner' : 'lease_owner';
        $untilColumn = $isProjectionOnly ? 'projection_lease_until' : 'lease_until';

        /** @var list<OutboxEventRecord> $records */
        $records = $connection->transaction(function () use (
            $connection,
            $table,
            $panel,
            $batchSize,
            $nowStr,
            $leaseOwner,
            $leaseUntilStr,
            $isProjectionOnly,
            $doneColumn,
            $ownerColumn,
            $untilColumn,
        ): array {
            $query = $connection->table($table)
                ->where('panel_id', $panel->value)
                ->whereNull($doneColumn)
                ->where('available_at', '<=', $nowStr)
                ->where(static function (Builder $q) use ($untilColumn, $nowStr): void {
                    $q->whereNull($untilColumn)
                        ->orWhere($untilColumn, '<=', $nowStr);
                })
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(max(1, $batchSize));

            if ($connection->getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $ids = [];
            $records = [];

            foreach ($rows as $row) {
                $record = $this->hydrate($row, incrementAttempts: ! $isProjectionOnly);
                $ids[] = $record->id;
                $records[] = $record;
            }

            $claimUpdate = [
                $ownerColumn => $leaseOwner,
                $untilColumn => $leaseUntilStr,
            ];

            if (! $isProjectionOnly) {
                $claimUpdate['attempts'] = $connection->raw('attempts + 1');
            }

            $connection->table($table)
                ->whereIn('id', $ids)
                ->update($claimUpdate);

            return $records;
        });

        $deliveredCount = 0;

        foreach ($records as $record) {
            try {
                foreach ($consumers as $consumer) {
                    if ($consumer->supports($record->eventType)) {
                        $consumer->consume($record, $mode);
                    }
                }

                $doneAt = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
                $successUpdate = [
                    $doneColumn => $doneAt,
                    $ownerColumn => null,
                    $untilColumn => null,
                ];

                if (! $isProjectionOnly) {
                    $successUpdate['last_problem_code'] = null;
                }

                $connection->table($table)
                    ->where('id', $record->id)
                    ->where($ownerColumn, $leaseOwner)
                    ->update($successUpdate);

                $deliveredCount++;
            } catch (Throwable $exception) {
                $failUpdate = [
                    $ownerColumn => null,
                    $untilColumn => null,
                ];

                if (! $isProjectionOnly) {
                    $failUpdate['last_problem_code'] = substr($exception::class, 0, 96);
                }

                $connection->table($table)
                    ->where('id', $record->id)
                    ->where($ownerColumn, $leaseOwner)
                    ->update($failUpdate);
            }
        }

        return $deliveredCount;
    }

    private function hydrate(object $row, bool $incrementAttempts): OutboxEventRecord
    {
        /** @var array<string, mixed> $payload */
        $payload = SockitRows::jsonArray($row, 'payload');

        return new OutboxEventRecord(
            id: SockitRows::str($row, 'id'),
            panel: new PanelId(SockitRows::str($row, 'panel_id')),
            aggregateType: SockitRows::str($row, 'aggregate_type'),
            aggregateId: SockitRows::str($row, 'aggregate_id'),
            aggregateRevision: SockitRows::int($row, 'aggregate_revision'),
            eventType: SockitRows::str($row, 'event_type'),
            eventSchemaVersion: SockitRows::int($row, 'event_schema_version'),
            payloadDigest: SockitRows::str($row, 'payload_digest'),
            payload: $payload,
            occurredAt: SockitRows::date($row, 'occurred_at'),
            attempts: SockitRows::int($row, 'attempts') + ($incrementAttempts ? 1 : 0),
            deliveredAt: SockitRows::optDate($row, 'delivered_at'),
        );
    }
}
