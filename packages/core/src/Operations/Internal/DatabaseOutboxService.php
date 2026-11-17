<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Packman\Attributes\Internal;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Data\OutboxEventRecord;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use Throwable;

/**
 * Panel-isolated transactional outbox relay using `SKIP LOCKED` on PostgreSQL
 * and passing explicit {@see ReplayMode} to registered {@see OutboxConsumer} slot handlers.
 */
#[Internal]
final readonly class DatabaseOutboxService implements OutboxRelay
{
    /** @param list<OutboxConsumer> $consumers */
    public function __construct(
        private SockitTables $tables,
        private PanelDefinitions $panels,
        private array $consumers = [],
    ) {}

    #[Override]
    public function relayPending(PanelId $panel, int $batchSize = 100, ReplayMode $mode = ReplayMode::Normal): int
    {
        $this->panels->get($panel);

        $connection = $this->tables->connection($panel);
        $table = $this->tables->table('outbox_events', $panel);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        $deliveredCount = 0;

        $records = $connection->transaction(function () use ($connection, $table, $panel, $batchSize, $now): array {
            $query = $connection->table($table)
                ->where('panel_id', $panel->value)
                ->whereNull('delivered_at')
                ->where('available_at', '<=', $now)
                ->orderBy('occurred_at')
                ->limit(max(1, $batchSize));

            if ($connection->getDriverName() === 'pgsql') {
                $query->lock('for update skip locked');
            }

            $rows = $query->get();
            $records = [];

            foreach ($rows as $row) {
                $records[] = $this->hydrate($row);
            }

            return $records;
        });

        foreach ($records as $record) {
            try {
                foreach ($this->consumers as $consumer) {
                    if ($consumer->supports($record->eventType)) {
                        $consumer->consume($record, $mode);
                    }
                }

                $connection->table($table)
                    ->where('id', $record->id)
                    ->update([
                        'delivered_at' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
                        'attempts' => $record->attempts + 1,
                        'last_problem_code' => null,
                    ]);

                $deliveredCount++;
            } catch (Throwable $exception) {
                $connection->table($table)
                    ->where('id', $record->id)
                    ->update([
                        'attempts' => $record->attempts + 1,
                        'last_problem_code' => substr($exception::class, 0, 96),
                    ]);
            }
        }

        return $deliveredCount;
    }

    private function hydrate(object $row): OutboxEventRecord
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
            attempts: SockitRows::int($row, 'attempts'),
            deliveredAt: SockitRows::optDate($row, 'delivered_at'),
        );
    }
}
