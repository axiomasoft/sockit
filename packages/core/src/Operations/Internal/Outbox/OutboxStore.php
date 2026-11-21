<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Outbox;

use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use SocKit\Configuration\Data\EventsConfig;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\DeliveryResult;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Ids;

/** One kernel logical outbox row, including all durable per-consumer state. No inbox table.
 * Append joins the caller's owner transaction. Claims and ACK each own a short transaction.
 * PostgreSQL clock, row locks and current random token fence expiry/reclaim, independent of worker clocks.
 */
#[Internal]
final readonly class OutboxStore
{
    public function __construct(private OwnerConnection $owner, private EventsConfig $config) {}

    /** @param list<string> $consumers Server-compiled IDs selected on this panel, never payload input. */
    public function append(EventEnvelope $event, array $consumers): void
    {
        $this->owner->requireTransaction();
        $handle = $this->owner->handle();
        if (! $event->surface->equals($handle->surface) || ! $event->sourceInstallation->equals($handle->actor->installation)
            || $event->sourceGeneration !== $this->config->sourceGeneration) {
            throw new OutboxException(ProblemCode::PanelBindingMismatch);
        }
        $delivery = [];
        foreach ($consumers as $consumer) {
            if (preg_match('/^[a-z][a-z0-9_.-]{0,95}$/D', $consumer) !== 1 || isset($delivery[$consumer])) {
                throw new InvalidPayloadException('Invalid compiled outbox consumer IDs');
            }
            $delivery[$consumer] = $this->state();
        }
        if ($delivery === []) {
            $delivery['core.unrouted'] = [...$this->state(), 'status' => 'quarantine', 'reason' => 'outbox.no_consumer', 'problem' => ProblemCode::ProviderUnavailable->value];
        }
        $query = $this->owner->table('outbox');
        $grammar = $query->getGrammar();
        $envelope = CanonicalJson::encode($event->toWire());
        $columns = [...$this->identity(), 'id' => Ids::uuid7(), 'source_generation' => $event->sourceGeneration, 'event_id' => $event->eventId,
            'digest' => $event->digest->value, 'envelope' => $envelope, 'deliveries' => json_encode($delivery, JSON_THROW_ON_ERROR)];
        $names = implode(', ', array_map($grammar->wrap(...), array_keys($columns)));
        $sql = 'INSERT INTO '.$grammar->wrapTable($query->from).' ('.$names.') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).') ON CONFLICT (installation,panel,source_generation,event_id) DO NOTHING RETURNING id';
        $inserted = $query->getConnection()->select(query: $sql, bindings: array_values($columns));
        if ($inserted === []) {
            $existing = $this->query()->where(column: 'source_generation', operator: '=', value: $event->sourceGeneration)->where(column: 'event_id', operator: '=', value: $event->eventId)->first();
            if ($existing === null || $existing->digest !== $event->digest->value || $existing->envelope !== $envelope) {
                throw new OutboxException(ProblemCode::EventIdentityConflict);
            }
        }
    }

    public function claim(): ?OutboxLease
    {
        return $this->owner->transaction(function (): ?OutboxLease {
            $row = $this->query()->whereRaw("EXISTS (SELECT 1 FROM jsonb_each(deliveries) AS d WHERE d.value->>'status' IN ('pending','retry','unknown','leased') AND COALESCE((d.value->>'until')::float8, 0) <= EXTRACT(EPOCH FROM clock_timestamp()) AND COALESCE((d.value->>'next_at')::float8, 0) <= EXTRACT(EPOCH FROM clock_timestamp()))")
                ->orderBy('event_id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if ($row === null) {
                return null;
            }
            $states = $this->states($row->deliveries);
            $now = $this->now();
            foreach ($states as $consumer => $state) {
                if (in_array($state['status'], ['delivered', 'quarantine'], true) || $state['until'] > $now || $state['next_at'] > $now) {
                    continue;
                }
                $token = bin2hex(random_bytes(24));
                $states[$consumer] = [...$state, 'status' => 'leased', 'token' => $token, 'until' => $now + $this->config->leaseSeconds, 'attempts' => $state['attempts'] + 1];
                $id = $this->text($row->id);
                $this->query()->where(column: 'id', operator: '=', value: $id)->update(['deliveries' => json_encode($states, JSON_THROW_ON_ERROR)]);

                return new OutboxLease(rowId: $id, eventId: $this->text($row->event_id), generation: $this->text($row->source_generation), consumerId: $consumer, attempt: $states[$consumer]['attempts'], token: $token,
                    encoded: $this->text($row->envelope), digest: $this->text($row->digest));
            }

            return null;
        });
    }

    /** False means this attempt lost its lease. It can never overwrite a newer worker's status. */
    public function acknowledge(OutboxLease $lease, DeliveryResult $result): bool
    {
        return $this->owner->transaction(function () use ($lease, $result): bool {
            $query = $this->query()->where(column: 'id', operator: '=', value: $lease->rowId)->where(column: 'event_id', operator: '=', value: $lease->eventId)->where(column: 'source_generation', operator: '=', value: $lease->generation);
            $row = $query->lockForUpdate()->first();
            if ($row === null) {
                return false;
            }
            $states = $this->states($row->deliveries);
            $state = $states[$lease->consumerId] ?? null;
            $now = $this->now();
            if ($state === null || $state['status'] !== 'leased' || $state['token'] !== $lease->token || $state['until'] <= $now) {
                return false;
            }
            $exhausted = ! $result->isSuccess() && $result->status !== 'quarantine' && $state['attempts'] >= $this->config->maxAttempts;
            $status = $exhausted ? 'quarantine' : ($result->isSuccess() ? 'delivered' : $result->status);
            $states[$lease->consumerId] = [...$state, 'status' => $status, 'token' => '', 'until' => 0, 'next_at' => $now + min(86400, $this->config->retrySeconds * (2 ** min(10, $state['attempts'] - 1))),
                'problem' => $result->problem?->value, 'reason' => $exhausted ? 'outbox.attempts_exhausted' : $result->reason];
            $this->query()->where(column: 'id', operator: '=', value: $lease->rowId)->where(column: 'event_id', operator: '=', value: $lease->eventId)->where(column: 'source_generation', operator: '=', value: $lease->generation)
                ->update(['deliveries' => json_encode($states, JSON_THROW_ON_ERROR)]);

            return true;
        });
    }

    /** Projection-only reads never consume/overwrite live delivery state.
     * @return list<array<string, mixed>> */
    public function projectionEvents(int $limit): array
    {
        $rows = $this->query()->orderBy('event_id')->limit($limit)->get();
        $events = [];
        foreach ($rows as $row) {
            $wire = $this->object(json_decode($this->text($row->envelope), true, flags: JSON_THROW_ON_ERROR));
            if (($wire['digest'] ?? null) !== $row->digest || ($wire['event_id'] ?? null) !== $row->event_id || ($wire['source_generation'] ?? null) !== $row->source_generation) {
                throw new OutboxException(ProblemCode::EventIdentityConflict);
            }
            $events[] = $wire;
        }

        return $events;
    }

    private function query(): Builder
    {
        return $this->owner->table('outbox')->where($this->identity());
    }

    /** @return array<string, string|int> */
    private function identity(): array
    {
        $handle = $this->owner->handle();
        $surface = $handle->surface;

        return ['installation' => $handle->actor->installation->id, 'panel' => $surface->panel->value, 'kind' => $surface->kind->value,
            'host_type' => $surface->host->type, 'host_key' => $surface->host->value, 'incarnation' => $surface->incarnation->value];
    }

    /** @return array{status:string, token:string, until:int|float, next_at:int|float, attempts:int, problem:?string, reason:string} */
    private function state(): array
    {
        return ['status' => 'pending', 'token' => '', 'until' => 0, 'next_at' => 0, 'attempts' => 0, 'problem' => null, 'reason' => 'outbox.pending'];
    }

    /** @return array<string, array{status:string, token:string, until:int|float, next_at:int|float, attempts:int, problem:?string, reason:string}> */
    private function states(mixed $value): array
    {
        $values = $this->object(is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value);
        $result = [];
        foreach ($values as $id => $item) {
            $state = $this->object($item);
            if (! isset($state['status'], $state['token'], $state['until'], $state['next_at'], $state['attempts'], $state['reason']) || ! array_key_exists('problem', $state)
                || ! is_string($state['status']) || ! in_array($state['status'], ['pending', 'leased', 'delivered', 'quarantine', 'retry', 'unknown'], true)
                || ! is_string($state['token']) || ! is_numeric($state['until']) || ! is_numeric($state['next_at']) || ! is_int($state['attempts'])
                || ! is_string($state['reason']) || ($state['problem'] !== null && ! is_string($state['problem']))) {
                throw new InvalidPayloadException('Invalid durable outbox state');
            }
            $result[$id] = ['status' => $state['status'], 'token' => $state['token'], 'until' => (float) $state['until'], 'next_at' => (float) $state['next_at'],
                'attempts' => $state['attempts'], 'problem' => $state['problem'], 'reason' => $state['reason']];
        }

        return $result;
    }

    private function now(): float
    {
        $row = $this->owner->table('outbox')->getConnection()->selectOne('SELECT EXTRACT(EPOCH FROM clock_timestamp())::float8 AS now');
        if (! $row instanceof \stdClass || ! is_float($row->now)) {
            throw new InvalidPayloadException('Invalid owner clock');
        }

        return $row->now;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidPayloadException('Invalid outbox text');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidPayloadException('Invalid outbox object');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidPayloadException('Invalid outbox object key');
            }
            $object[$key] = $item;
        }

        return $object;
    }
}
