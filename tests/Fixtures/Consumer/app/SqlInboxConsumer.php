<?php

declare(strict_types=1);

namespace Acme;

use Illuminate\Database\Connection;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Data\DeliveryResult;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Enums\ReplayMode;

/** Consumer-owned SQL inbox/effects. Independent PDO and transaction, not a kernel table or an array fake. */
final class SqlInboxConsumer implements OutboxConsumer
{
    /** @var list<int> */
    public array $ownerLevels = [];

    /** @var list<string> */
    public array $digests = [];

    public string $fault = '';

    public int $calls = 0;

    public int $deliveries = 0;

    public function __construct(private readonly Connection $owner, private \PDO $consumer, private readonly string $id = 'acme.inbox') {}

    public function close(): void
    {
        unset($this->consumer);
    }

    public function backendPid(): int
    {
        $pid = PgTarget::query(pdo: $this->consumer, sql: 'SELECT pg_backend_pid()')->fetchColumn();
        if (! is_int($pid)) {
            throw new \RuntimeException('Invalid consumer PDO backend PID');
        }

        return $pid;
    }

    public function consume(EventEnvelope $event, ReplayMode $mode): DeliveryResult
    {
        $this->calls++;
        $level = $this->owner->transactionLevel();
        $this->ownerLevels[] = $level;
        if ($level !== 0 || $this->consumer->inTransaction()) {
            throw new \RuntimeException('Consumer invoked in an active transaction');
        }
        $this->digests[] = $event->digest->value;
        fwrite(STDERR, 'P2.6 consume owner_level='.$level.' consumer_backend='.PgTarget::query(pdo: $this->consumer, sql: 'SELECT pg_backend_pid()')->fetchColumn().' mode='.$mode->value.' event='.$event->eventId.PHP_EOL);
        if ($this->fault === 'exit-before') {
            echo "crash-before\n";
            exit(0);
        }
        if ($this->fault === 'before') {
            throw new \RuntimeException('Crash before consume');
        }
        if ($this->fault === 'retry') {
            return DeliveryResult::retry();
        }
        if ($this->fault === 'unknown') {
            return DeliveryResult::unknown();
        }
        $key = [$this->id, $event->sourceInstallation->id, $event->panel->value, $event->sourceGeneration, $event->eventId];
        $this->consumer->beginTransaction();
        try {
            if ($mode === ReplayMode::ProjectionOnly) {
                $query = $this->consumer->prepare('INSERT INTO acme_consumer.projections (consumer,installation,panel,source_generation,event_id,digest) VALUES (?,?,?,?,?,?) ON CONFLICT DO NOTHING');
                $query->execute([...$key, $event->digest->value]);
                $this->consumer->commit();

                return DeliveryResult::confirmed();
            }
            $insert = $this->consumer->prepare('INSERT INTO acme_consumer.inbox (consumer,installation,panel,source_generation,event_id,digest) VALUES (?,?,?,?,?,?) ON CONFLICT (consumer,installation,panel,source_generation,event_id) DO NOTHING RETURNING event_id');
            $insert->execute([...$key, $event->digest->value]);
            $first = $insert->fetchColumn() !== false;
            $lookup = $this->consumer->prepare('SELECT digest FROM acme_consumer.inbox WHERE consumer=? AND installation=? AND panel=? AND source_generation=? AND event_id=?');
            $lookup->execute($key);
            if ($lookup->fetchColumn() !== $event->digest->value) {
                $this->consumer->rollBack();

                return DeliveryResult::quarantine(problem: ProblemCode::EventIdentityConflict, reason: 'outbox.event_identity_conflict');
            }
            if ($first) {
                $effect = $this->consumer->prepare('INSERT INTO acme_consumer.effects (consumer,installation,panel,source_generation,event_id,digest) VALUES (?,?,?,?,?,?)');
                $effect->execute([...$key, $event->digest->value]);
            }
            if ($this->fault === 'exit-during') {
                echo "crash-during\n";
                exit(0);
            }
            if ($this->fault === 'during') {
                throw new \RuntimeException('Crash between inbox and effect commit');
            }
            $this->consumer->commit();
            if ($first) {
                $this->deliveries++;
            }
        } catch (\Throwable $error) {
            $this->consumer->rollBack();
            throw $error;
        }
        if ($this->fault === 'exit-after') {
            echo "crash-after\n";
            exit(0);
        }
        if ($this->fault === 'after') {
            throw new \RuntimeException('Crash after consumer commit before ACK');
        }

        return DeliveryResult::confirmed();
    }
}
