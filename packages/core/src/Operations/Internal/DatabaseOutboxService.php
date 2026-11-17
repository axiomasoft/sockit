<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use JsonException;
use Override;
use Packman\Attributes\Internal;
use RuntimeException;
use SocKit\Database\Internal\SockitRows;
use SocKit\Database\Internal\SockitTables;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Data\OutboxEventRecord;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Ids;
use Throwable;

/**
 * Panel-isolated transactional outbox relay establishing durable `lease_owner`/`lease_until` claims
 * via `SKIP LOCKED` on PostgreSQL, verifying schema version and canonical digest before delivery,
 * never marking delivery without supporting consumers, and tracking per-consumer completion idempotently.
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
        $modeKey = $isProjectionOnly ? 'projection' : 'normal';
        $doneColumn = $isProjectionOnly ? 'projection_replayed_at' : 'delivered_at';
        $ownerColumn = $isProjectionOnly ? 'projection_lease_owner' : 'lease_owner';
        $untilColumn = $isProjectionOnly ? 'projection_lease_until' : 'lease_until';

        /** @var list<array{record: OutboxEventRecord, deliveredConsumers: array<string, array<string, string>>}> $claimed */
        $claimed = $connection->transaction(function () use (
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
            $claimed = [];

            foreach ($rows as $row) {
                $record = $this->hydrate($row, incrementAttempts: ! $isProjectionOnly);
                $ids[] = $record->id;
                $claimed[] = [
                    'record' => $record,
                    'deliveredConsumers' => $this->decodeDeliveredConsumers($row),
                ];
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

            return $claimed;
        });

        $deliveredCount = 0;

        foreach ($claimed as $item) {
            $record = $item['record'];
            $deliveredConsumers = $item['deliveredConsumers'];
            $modeAcked = $deliveredConsumers[$modeKey] ?? [];

            if ($record->eventSchemaVersion !== 1) {
                $this->releaseLeaseWithProblem(
                    $connection,
                    $table,
                    $record->id,
                    $ownerColumn,
                    $untilColumn,
                    $leaseOwner,
                    $isProjectionOnly ? null : 'outbox.unsupported_schema_version',
                    $deliveredConsumers,
                );

                continue;
            }

            $expectedDigest = PayloadDigest::of($record->eventType, $record->payload)->value;
            if (! hash_equals($expectedDigest, $record->payloadDigest)) {
                $this->releaseLeaseWithProblem(
                    $connection,
                    $table,
                    $record->id,
                    $ownerColumn,
                    $untilColumn,
                    $leaseOwner,
                    $isProjectionOnly ? null : 'outbox.digest_mismatch',
                    $deliveredConsumers,
                );

                continue;
            }

            $supporting = array_values(array_filter(
                $consumers,
                static fn (OutboxConsumer $consumer): bool => $consumer->supports($record->eventType),
            ));

            if ($supporting === []) {
                $this->releaseLeaseWithProblem(
                    $connection,
                    $table,
                    $record->id,
                    $ownerColumn,
                    $untilColumn,
                    $leaseOwner,
                    $isProjectionOnly ? null : 'outbox.no_supporting_consumer',
                    $deliveredConsumers,
                );

                continue;
            }

            $failedException = null;

            foreach ($supporting as $consumer) {
                $consumerKey = $consumer->consumerKey();

                if (isset($modeAcked[$consumerKey])) {
                    continue;
                }

                try {
                    $consumer->consume($record, $mode);
                    $modeAcked[$consumerKey] = $this->nowIso();
                    $deliveredConsumers[$modeKey] = $modeAcked;
                } catch (Throwable $exception) {
                    $failedException = $exception;
                }
            }
            $allDelivered = array_all($supporting, fn ($consumer): bool => isset($modeAcked[$consumer->consumerKey()]));

            if ($failedException instanceof Throwable || ! $allDelivered) {
                $this->releaseLeaseWithProblem(
                    $connection,
                    $table,
                    $record->id,
                    $ownerColumn,
                    $untilColumn,
                    $leaseOwner,
                    ! $isProjectionOnly && $failedException instanceof Throwable ? substr($failedException::class, 0, 96) : null,
                    $deliveredConsumers,
                );

                continue;
            }

            $doneAt = $this->nowIso();
            $successUpdate = [
                $doneColumn => $doneAt,
                $ownerColumn => null,
                $untilColumn => null,
                'delivered_consumers' => $this->encodeDeliveredConsumers($deliveredConsumers),
            ];

            if (! $isProjectionOnly) {
                $successUpdate['last_problem_code'] = null;
            }

            $connection->table($table)
                ->where('id', $record->id)
                ->where($ownerColumn, $leaseOwner)
                ->update($successUpdate);

            $deliveredCount++;
        }

        return $deliveredCount;
    }

    /**
     * @param  array<string, array<string, string>>  $deliveredConsumers
     */
    private function releaseLeaseWithProblem(
        Connection $connection,
        string $table,
        string $recordId,
        string $ownerColumn,
        string $untilColumn,
        string $leaseOwner,
        ?string $problemCode,
        array $deliveredConsumers,
    ): void {
        $update = [
            $ownerColumn => null,
            $untilColumn => null,
            'delivered_consumers' => $this->encodeDeliveredConsumers($deliveredConsumers),
        ];

        if ($problemCode !== null) {
            $update['last_problem_code'] = $problemCode;
        }

        $connection->table($table)
            ->where('id', $recordId)
            ->where($ownerColumn, $leaseOwner)
            ->update($update);
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

    /** @return array<string, array<string, string>> */
    private function decodeDeliveredConsumers(object $row): array
    {
        $raw = property_exists($row, 'delivered_consumers') ? $row->delivered_consumers : null;

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = SockitRows::jsonArray($row, 'delivered_consumers');
        $clean = [];

        foreach ($decoded as $mode => $entries) {
            if (! is_string($mode) || ! is_array($entries)) {
                continue;
            }

            foreach ($entries as $consumerKey => $timestamp) {
                if (is_string($consumerKey) && is_string($timestamp)) {
                    $clean[$mode][$consumerKey] = $timestamp;
                }
            }
        }

        return $clean;
    }

    /** @param array<string, array<string, string>> $deliveredConsumers */
    private function encodeDeliveredConsumers(array $deliveredConsumers): ?string
    {
        if ($deliveredConsumers === []) {
            return null;
        }

        try {
            return json_encode($deliveredConsumers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to serialize outbox delivered_consumers JSON: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function nowIso(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
