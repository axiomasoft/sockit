<?php

declare(strict_types=1);

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\Internal\Outbox\OutboxException;
use SocKit\Operations\OutboxRelay;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Fixtures\Runtime\SqlInboxConsumer;
use SocKit\Tests\Integration\Support\PgTarget;

it('deduplicates complete event identity in a real consumer SQL transaction and rejects another digest', function (): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $s->append($event);
        $s->append($event); // Stable append is a no-op; existing consumer state is preserved.
        $wire = $event->toWire();
        expect(EventEnvelope::fromWire($wire)->toWire())->toBe($wire);
        $relay = $s->app->make(OutboxRelay::class);
        expect($relay->poll($s->handle())[0]->isSuccess())->toBeTrue();
        expect($s->consumer->consume(event: $event, mode: ReplayMode::Delivery)->isSuccess())->toBeTrue();
        expect($s->count())->toBe(1)->and($s->count('inbox'))->toBe(1);
        $different = OutboxScenario::rewire(event: $event, changes: ['event_type' => 'acme.runtime.other']);
        $result = $s->consumer->consume(event: $different, mode: ReplayMode::Delivery);
        expect($result->status)->toBe('quarantine')->and($result->problem)->toBe(ProblemCode::EventIdentityConflict)->and($result->isSuccess())->toBeFalse()
            ->and($s->count())->toBe(1)->and($s->count('inbox'))->toBe(1);
        expect(fn () => $s->append($different))->toThrow(OutboxException::class);
        expect((int) PgTarget::query(pdo: $s->db->owner, sql: 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(1)
            ->and(array_unique($s->consumer->ownerLevels))->toBe([0]);
        $row = PgTarget::query(pdo: $s->db->owner, sql: 'SELECT digest FROM acme_consumer.effects')->fetchColumn();
        expect($row)->toBe($event->digest->value);
    } finally {
        $s->cleanup();
    }
});

it('keeps equal event IDs independent by panel, installation, source generation and consumer ID', function (): void {
    $s = new OutboxScenario;
    try {
        $a = $s->event();
        $b = $s->event(panel: 'acme.b', id: $a->eventId);
        foreach ([$a, $b] as $event) {
            $s->append($event);
        }
        $relay = $s->app->make(OutboxRelay::class);
        expect($relay->poll($s->handle())[0]->isSuccess())->toBeTrue()->and($relay->poll($s->handle('acme.b'))[0]->isSuccess())->toBeTrue();
        $generation = OutboxScenario::rewire(event: $a, changes: ['source_generation' => '2']);
        $installation = OutboxScenario::rewire(event: $a, changes: ['source_installation' => 'other-installation']);
        foreach ([$generation, $installation] as $event) {
            foreach ([1, 2] as $repeat) {
                expect($s->consumer->consume(event: $event, mode: ReplayMode::Delivery)->isSuccess())->toBeTrue();
            }
        }
        $other = new SqlInboxConsumer(owner: $s->db->capsule->getConnection('owner_a'), consumer: PgTarget::fromEnvironment()->connect(), id: 'acme.other');
        foreach ([1, 2] as $repeat) {
            expect($other->consume(event: $a, mode: ReplayMode::Delivery)->isSuccess())->toBeTrue();
        }
        expect($s->count())->toBe(5)->and($s->count('inbox'))->toBe(5)
            ->and((int) PgTarget::query(pdo: $s->db->owner, sql: 'SELECT count(DISTINCT event_id) FROM acme_consumer.effects')->fetchColumn())->toBe(1);
        $identities = PgTarget::query(pdo: $s->db->owner, sql: 'SELECT consumer,installation,panel,source_generation,event_id FROM acme_consumer.effects ORDER BY consumer,installation,panel,source_generation')->fetchAll(PDO::FETCH_ASSOC);
        fwrite(STDERR, 'P2.6 real inbox complete identities='.json_encode($identities, JSON_THROW_ON_ERROR).PHP_EOL);
    } finally {
        $s->cleanup();
    }
});

it('consumer inbox and effect roll back together and commit-before-ACK retry does not duplicate an effect', function (string $fault): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $s->append($event);
        $s->consumer->fault = $fault;
        $relay = $s->app->make(OutboxRelay::class);
        $result = $relay->poll($s->handle());
        expect($result[0]->status)->toBe('unknown')->and($result[0]->isSuccess())->toBeFalse()
            ->and($s->count())->toBe($fault === 'after' ? 1 : 0)->and($s->count('inbox'))->toBe($fault === 'after' ? 1 : 0);
        usleep(1100000);
        $s->consumer->fault = '';
        expect($relay->poll($s->handle())[0]->isSuccess())->toBeTrue()->and($s->count())->toBe(1)->and($s->count('inbox'))->toBe(1)
            ->and($s->consumer->digests)->toBe([$event->digest->value, $event->digest->value])->and($s->consumer->ownerLevels)->toBe([0, 0]);
    } finally {
        $s->cleanup();
    }
})->with(['during', 'after']);

it('serializes a consumer UNIQUE conflict against an uncommitted inbox effect on independent PDO backends', function (bool $conflict): void {
    $s = new OutboxScenario;
    $holder = PgTarget::fromEnvironment()->connect();
    $process = null;
    try {
        $event = $s->event();
        $s->append($event);
        $digest = $conflict ? OutboxScenario::rewire(event: $event, changes: ['event_type' => 'acme.runtime.other'])->digest->value : $event->digest->value;
        $holder->beginTransaction();
        foreach (['inbox', 'effects'] as $table) {
            $insert = $holder->prepare('INSERT INTO acme_consumer.'.$table.' (consumer,installation,panel,source_generation,event_id,digest) VALUES (?,?,?,?,?,?)');
            $insert->execute(['acme.inbox', $event->sourceInstallation->id, $event->panel->value, $event->sourceGeneration, $event->eventId, $digest]);
        }
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Runtime/outbox-child.php', 'inbox'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start inbox contender');
        }
        stream_set_timeout($pipes[1], 10);
        $workerOwnerPid = (int) fgets($pipes[1]);
        $consumerPid = (int) fgets($pipes[1]);
        $holderPid = PgTarget::query(pdo: $holder, sql: 'SELECT pg_backend_pid()')->fetchColumn();
        expect($consumerPid)->toBeGreaterThan(0)->and($workerOwnerPid)->not->toBe($consumerPid)->and($consumerPid)->not->toBe($holderPid);
        $deadline = microtime(true) + 5;
        do {
            $query = $s->db->owner->prepare("SELECT CASE WHEN wait_event_type='Lock' AND ? = ANY(pg_blocking_pids(pid)) THEN 1 ELSE 0 END FROM pg_stat_activity WHERE pid=?");
            $query->execute([$holderPid, $consumerPid]);
            $blocked = $query->fetchColumn() === 1;
            if (! $blocked) {
                usleep(10000);
            }
        } while (! $blocked && microtime(true) < $deadline);
        expect($blocked)->toBeTrue();
        fwrite(STDERR, 'P2.6 inbox barrier holder='.$holderPid.' consumer='.$consumerPid.' wait_event=Lock digest_conflict='.($conflict ? 'yes' : 'no').PHP_EOL);
        $holder->commit();
        expect(trim((string) fgets($pipes[1])))->toBe($conflict ? 'quarantine' : 'confirmed');
        $errors = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($process);
        $process = null;
        expect($exit)->toBe(0, (string) $errors)->and($s->count())->toBe(1)->and($s->count('inbox'))->toBe(1);
        fwrite(STDERR, (string) $errors);
    } finally {
        if ($holder->inTransaction()) {
            $holder->rollBack();
        }
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        $s->cleanup();
    }
})->with([false, true]);

it('rejects stored digest conflicts in both projection and delivery without exposing content or consuming', function (): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        foreach ([['body' => 'content'], ['resource' => ['panel' => 'acme.b', 'kind' => 'post', 'id' => $event->eventId]]] as $payload) {
            $wire = $event->toWire();
            $wire['payload'] = $payload;
            expect(fn () => EventEnvelope::fromWire($wire))->toThrow(InvalidPayloadException::class);
        }
        $s->append($event);
        $query = $s->db->owner->prepare('UPDATE panel_a.driver_a_outbox SET digest=?');
        $query->execute(['sha256:'.str_repeat('0', 64)]);
        $relay = $s->app->make(OutboxRelay::class);
        $projection = $relay->poll(handle: $s->handle(), mode: ReplayMode::ProjectionOnly);
        expect($projection[0]->problem)->toBe(ProblemCode::EventIdentityConflict)->and($projection[0]->isSuccess())->toBeFalse()
            ->and($s->consumer->calls)->toBe(0)->and($s->count('projections'))->toBe(0);
        $delivery = $relay->poll($s->handle());
        expect($delivery[0]->problem)->toBe(ProblemCode::EventIdentityConflict)->and($delivery[0]->status)->toBe('quarantine')->and($s->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
});
