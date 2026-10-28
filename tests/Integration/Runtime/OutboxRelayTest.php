<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\DeliveryResult;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\Internal\Outbox\OutboxException;
use SocKit\Operations\Internal\Outbox\OutboxStore;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\OutboxRelay;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\SurfaceLifecycle;
use SocKit\Surfaces\Enums\TargetLifecycle;
use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Runtime\SqlInboxConsumer;
use SocKit\Tests\Fixtures\Runtime\SurfaceScenario;
use SocKit\Tests\Fixtures\Runtime\SwapInboxConsumer;
use SocKit\Tests\Integration\Support\PgTarget;
use Symfony\Component\Process\Process;

it('polls only committed outbox events after a missed wakeup and rolls back append with state', function (): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $owner = $s->owner();
        $store = $s->store();
        $peer = PgTarget::fromEnvironment()->connect();
        $owner->transaction(function () use ($s, $event, $owner, $store, $peer): void {
            $store->append(event: $event, consumers: ['acme.inbox']);
            expect((int) PgTarget::query(pdo: $peer, sql: 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(0);
            expect(fn () => $s->app->make(OutboxRelay::class)->poll($owner->handle()))->toThrow(InvalidConfigurationException::class);
        });
        expect((int) PgTarget::query(pdo: $peer, sql: 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(1);
        // No wakeup registered at all: the durable sweep is sufficient.
        $results = $s->app->make(OutboxRelay::class)->poll($s->handle());
        expect($results)->toHaveCount(1)->and($results[0]->isSuccess())->toBeTrue()->and($s->count())->toBe(1)
            ->and($s->consumer->ownerLevels)->toBe([0]);
        $next = $s->event();
        try {
            $owner->transaction(function () use ($store, $next, $owner): void {
                $owner->table('receipts')->insert(['id' => 'rollback', 'payload' => 'state']);
                $store->append(event: $next, consumers: ['acme.inbox']);
                throw new RuntimeException('Fault after outbox append');
            });
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('Fault after outbox append');
        }
        expect($owner->table('receipts')->where('id', 'rollback')->exists())->toBeFalse()
            ->and((int) PgTarget::query(pdo: $peer, sql: 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(1)
            ->and($s->app->make(OutboxRelay::class)->poll($s->handle()))->toBe([]);
    } finally {
        $s->cleanup();
    }
});

it('two real relay processes use SKIP LOCKED and one durable lease before any consume', function (): void {
    $s = new OutboxScenario;
    $processes = $streams = [];
    $first = 0;
    try {
        $s->append($s->event());
        foreach (['hold', 'claim'] as $i => $action) {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Runtime/outbox-child.php', $action], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot start relay worker');
            }
            $processes[$i] = $process;
            $streams[$i] = $pipes;
            stream_set_timeout($pipes[1], 10);
            $pid = (int) fgets($pipes[1]);
            expect($pid)->toBeGreaterThan(0);
            if ($i === 0) {
                expect(trim((string) fgets($pipes[1])))->toBe('locked');
                $first = $pid;
            } else {
                expect($pid)->not->toBe($first)->and(trim((string) fgets($pipes[1])))->toBe('none');
                fwrite(STDERR, 'P2.6 SKIP LOCKED observed holder='.$first.' peer='.$pid." peer_claim=none\n");
            }
        }
        fwrite($streams[0][0], "release\n");
        fflush($streams[0][0]);
        $token = trim((string) fgets($streams[0][1]));
        expect(strlen($token))->toBe(48)->and($s->store()->claim())->toBeNull();
        foreach ($processes as $i => $process) {
            $errors = stream_get_contents($streams[$i][2]);
            foreach ($streams[$i] as $pipe) {
                fclose($pipe);
            }
            expect(proc_close($process))->toBe(0, (string) $errors);
            unset($processes[$i]);
            fwrite(STDERR, (string) $errors);
        }
        expect($s->count())->toBe(0);
    } finally {
        foreach ($processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        $s->cleanup();
    }
});

it('fences an expired worker ACK and preserves the newer token and per-consumer status', function (): void {
    $s = new OutboxScenario;
    try {
        $s->append($s->event());
        $store = $s->store();
        $old = $store->claim();
        expect($old)->not->toBeNull();
        if ($old === null) {
            throw new RuntimeException('Missing lease');
        }
        usleep(1100000);
        expect($store->acknowledge(lease: $old, result: DeliveryResult::confirmed()))->toBeFalse();
        $new = $store->claim();
        if ($new === null) {
            throw new RuntimeException('Expired lease was not reclaimed');
        }
        expect($new->token)->not->toBe($old->token)->and($new->eventId)->toBe($old->eventId)->and($new->digest)->toBe($old->digest);
        expect($store->acknowledge(lease: $old, result: DeliveryResult::quarantine(problem: ProblemCode::EventIdentityConflict, reason: 'outbox.stale')))->toBeFalse()
            ->and($store->acknowledge(lease: $new, result: DeliveryResult::confirmed()))->toBeTrue()
            ->and($store->acknowledge(lease: $old, result: DeliveryResult::confirmed()))->toBeFalse()->and($store->claim())->toBeNull();
        $status = PgTarget::query(pdo: $s->db->owner, sql: "SELECT deliveries->'acme.inbox'->>'status' FROM panel_a.driver_a_outbox")->fetchColumn();
        expect($status)->toBe('delivered');
    } finally {
        $s->cleanup();
    }
});

it('recovers an abruptly terminated process with the same identity and one SQL effect', function (string $point): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $s->append($event);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Runtime/outbox-child.php', 'exit-'.$point]);
        $process->mustRun();
        fwrite(STDERR, $process->getErrorOutput());
        expect($process->getOutput())->toContain('crash-'.$point);
        expect($s->count())->toBe($point === 'after' ? 1 : 0)->and($s->count('inbox'))->toBe($point === 'after' ? 1 : 0);
        $status = PgTarget::query(pdo: $s->db->owner, sql: "SELECT deliveries->'acme.inbox'->>'status' FROM panel_a.driver_a_outbox")->fetchColumn();
        expect($status)->toBe('leased'); // No ACK survived the process exit.
        usleep(1100000);
        $result = $s->app->make(OutboxRelay::class)->poll($s->handle());
        expect($result)->toHaveCount(1)->and($result[0]->isSuccess())->toBeTrue()->and($s->count())->toBe(1)->and($s->count('inbox'))->toBe(1)
            ->and($s->consumer->digests)->toBe([$event->digest->value])->and($s->consumer->ownerLevels)->toBe([0]);
    } finally {
        $s->cleanup();
    }
})->with(['before', 'during', 'after']);

it('unknown and retry remain undelivered and the relay alone retries or quarantines exhausted attempts', function (): void {
    $s = new OutboxScenario;
    try {
        $s->append($s->event());
        foreach (['unknown', 'retry', 'before'] as $index => $fault) {
            $s->consumer->fault = $fault;
            $result = $s->app->make(OutboxRelay::class)->poll($s->handle());
            expect($result)->toHaveCount(1)->and($result[0]->isSuccess())->toBeFalse()->and($s->count())->toBe(0);
            expect($s->app->make(OutboxRelay::class)->poll($s->handle()))->toBe([]);
            if ($index < 2) {
                usleep((1000000 * (2 ** $index)) + 100000);
            }
        }
        $row = PgTarget::query(pdo: $s->db->owner, sql: "SELECT deliveries->'acme.inbox'->>'status' AS status, deliveries->'acme.inbox'->>'reason' AS reason FROM panel_a.driver_a_outbox")->fetch(PDO::FETCH_ASSOC);
        expect($row)->toBe(['status' => 'quarantine', 'reason' => 'outbox.attempts_exhausted']);
        expect($s->app->make(OutboxRelay::class)->poll($s->handle()))->toBe([]);
    } finally {
        $s->cleanup();
    }
});

it('quarantines disabled or missing consumers durably without resolving them or discarding pending events', function (): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $s->append($event);
        $disabled = OutboxScenario::app(selected: false);
        $fake = RuntimeScenario::trust($disabled);
        $db = new RuntimeDatabase($disabled);
        $disabled->bind(abstract: SqlInboxConsumer::class, concrete: static fn () => throw new RuntimeException('Inactive consumer resolved'));
        $handle = $disabled->make(OperationHandles::class)->forSurface($event->surface);
        $result = $disabled->make(OutboxRelay::class)->poll($handle);
        expect($result)->toHaveCount(1)->and($result[0]->status)->toBe('quarantine')->and($result[0]->reason)->toBe('outbox.consumer_unavailable')
            ->and($s->consumer->calls)->toBe(0)->and($s->count())->toBe(0);
        expect((int) PgTarget::query(pdo: $db->owner, sql: 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(1)
            ->and($s->app->make(OutboxRelay::class)->poll($s->handle()))->toBe([]);
    } finally {
        $s->cleanup();
    }
});

it('rebuilds projections without delivery effects or suppressing a later live inbox effect', function (): void {
    $s = new OutboxScenario;
    try {
        $s->append($s->event());
        $relay = $s->app->make(OutboxRelay::class);
        foreach ([1, 2] as $run) {
            $result = $relay->poll(handle: $s->handle(), mode: ReplayMode::ProjectionOnly);
            expect($result)->toHaveCount(1)->and($result[0]->isSuccess())->toBeTrue()->and($s->count('projections'))->toBe(1)
                ->and($s->count('inbox'))->toBe(0)->and($s->count())->toBe(0)->and($s->consumer->deliveries)->toBe(0);
        }
        expect($relay->poll($s->handle())[0]->isSuccess())->toBeTrue()->and($s->count())->toBe(1)->and($s->consumer->deliveries)->toBe(1);
        expect(array_unique($s->consumer->ownerLevels))->toBe([0]);
    } finally {
        $s->cleanup();
    }
});

it('rejects current access, deleted state and stale/corrupt immutable events before consume', function (string $fault, ProblemCode $problem): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $s->append($event);
        $handle = $s->handle();
        if ($fault === 'action') {
            $s->fake->authorizer->deny(action: 'sockit.events.deliver', reason: 'host.revoked');
        }
        if ($fault === 'admission') {
            $s->fake->surfaces->deny(viewer: $handle->actor, surface: $event->surface);
        }
        if ($fault === 'audience') {
            $s->fake->audience->verdict(key: $event->eventId, verdict: AudienceVerdict::deny('audience.unknown'));
        }
        if ($fault === 'actor') {
            $s->fake->actors->register(RuntimeScenario::actor(generation: 'revoked'));
        }
        if ($fault === 'delete' || $fault === 'revision') {
            $owner = $s->owner();
            $owner->transaction(function () use ($owner, $fault): void {
                $instances = new SurfaceInstances($owner);
                if ($fault === 'delete') {
                    $instances->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(1), sourceGeneration: new Revision(2), privacyGeneration: new Revision(2));
                } else {
                    $instances->revise(expectedRevision: new Revision(1), sourceGeneration: new Revision(2), privacyGeneration: new Revision(2));
                }
            });
        }
        if ($fault === 'malformed') {
            $s->db->owner->exec("UPDATE panel_a.driver_a_outbox SET envelope = '{'");
        }
        if (in_array($fault, ['major', 'digest'], true)) {
            $wire = $event->toWire();
            $wire[$fault === 'major' ? 'version' : 'digest'] = $fault === 'major' ? '2.0' : 'sha256:'.str_repeat('0', 64);
            $query = $s->db->owner->prepare('UPDATE panel_a.driver_a_outbox SET envelope=?');
            $query->execute([json_encode($wire, JSON_THROW_ON_ERROR)]);
        }
        $result = $s->app->make(OutboxRelay::class)->poll($handle);
        expect($result)->toHaveCount(1)->and($result[0]->status)->toBe('quarantine')->and($result[0]->problem)->toBe($problem)
            ->and($s->consumer->calls)->toBe(0)->and($s->count())->toBe(0);
        expect(PgTarget::query(pdo: $s->db->owner, sql: "SELECT deliveries->'acme.inbox'->>'status' FROM panel_a.driver_a_outbox")->fetchColumn())->toBe('quarantine');
    } finally {
        $s->cleanup();
    }
})->with([
    'action' => ['action', ProblemCode::AuthorizationDenied], 'admission' => ['admission', ProblemCode::AudienceDenied],
    'audience' => ['audience', ProblemCode::AudienceDenied], 'actor' => ['actor', ProblemCode::ActorContextInvalid],
    'delete' => ['delete', ProblemCode::ContentDeleted], 'revision' => ['revision', ProblemCode::RevisionConflict],
    'malformed event' => ['malformed', ProblemCode::PayloadInvalid],
    'unknown major' => ['major', ProblemCode::WireVersionUnsupported], 'digest conflict' => ['digest', ProblemCode::EventIdentityConflict],
]);

it('checks source generation from frozen server config and quarantines old source after rotation', function (): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $s->append($event);
        $rotated = OutboxScenario::app(generation: '2');
        RuntimeScenario::trust($rotated);
        $db = new RuntimeDatabase($rotated);
        $handle = $rotated->make(OperationHandles::class)->forSurface($event->surface);
        $store = new OutboxStore(owner: $rotated->make(OwnerStorage::class)->forHandle($handle), config: $rotated->make(SocKitConfig::class)->events);
        $owner = $rotated->make(OwnerStorage::class)->forHandle($handle);
        expect(fn () => $owner->transaction(fn () => $store->append(event: $event, consumers: ['acme.inbox'])))->toThrow(OutboxException::class);
        $result = $rotated->make(OutboxRelay::class)->poll($handle);
        expect($result[0]->isSuccess())->toBeFalse()->and($result[0]->status)->toBe('quarantine')->and($s->count())->toBe(0)
            ->and((int) PgTarget::query(pdo: $db->owner, sql: 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('isolates per-consumer ACKs and supports a concrete consumer swap through the public port', function (): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $owner = $s->owner();
        $store = $s->store();
        $owner->transaction(fn () => $store->append(event: $event, consumers: ['acme.inbox', 'acme.missing']));
        $swap = new SwapInboxConsumer($s->consumer);
        $s->app->bind(abstract: SqlInboxConsumer::class, concrete: fn () => $swap);
        $results = $s->app->make(OutboxRelay::class)->poll($s->handle());
        $statuses = array_map(fn (DeliveryResult $result): string => $result->status, $results);
        sort($statuses);
        expect($statuses)->toBe(['confirmed', 'quarantine'])->and($s->count())->toBe(1)->and($swap->calls)->toBe(1);
        $states = PgTarget::query(pdo: $s->db->owner, sql: "SELECT deliveries->'acme.inbox'->>'status' AS inbox, deliveries->'acme.missing'->>'status' AS missing FROM panel_a.driver_a_outbox")->fetch(PDO::FETCH_ASSOC);
        expect($states)->toBe(['inbox' => 'delivered', 'missing' => 'quarantine']);
        $s->append($event);
        expect($s->app->make(OutboxRelay::class)->poll($s->handle()))->toBe([])->and($s->count())->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('rehydrates target visibility and privacy on the public target port before delivery', function (string $state): void {
    $s = new OutboxScenario;
    try {
        $base = $s->event();
        $target = new TargetRef(host: new HostEntityRef(type: 'article', value: '00042:host'), incarnation: new Incarnation(1));
        $event = OutboxScenario::rewire(event: $base, changes: ['target' => $target->toWire()]);
        $handle = $s->handle();
        $resolved = new TargetResolution(surface: $event->surface, target: $target, installation: $event->sourceInstallation,
            read: AudienceVerdict::allow('audience.allowed.platform'), write: AudienceVerdict::allow('audience.allowed.platform'),
            lifecycle: TargetLifecycle::from($state === 'privacy' ? 'live' : $state), privacyRevision: new Revision($state === 'privacy' ? 2 : 1), generation: 'current-target');
        $s->fake->targets->returns(actor: $handle->actor, resolved: $resolved);
        $s->append($event);
        $result = $s->app->make(OutboxRelay::class)->poll($handle);
        $allowed = $state === 'live';
        expect($result[0]->isSuccess())->toBe($allowed)->and($s->consumer->calls)->toBe($allowed ? 1 : 0)->and($s->count())->toBe($allowed ? 1 : 0);
    } finally {
        $s->cleanup();
    }
})->with(['live', 'hidden', 'deleted', 'unknown', 'privacy']);

it('quarantines a reference to another resource instead of dispatching without its current owner lookup', function (): void {
    $s = new OutboxScenario;
    try {
        $event = OutboxScenario::rewire(event: $s->event(), changes: ['payload' => ['resource' => ['panel' => 'acme.a', 'kind' => 'post', 'id' => Ids::uuid7()]]]);
        $s->append($event);
        $result = $s->app->make(OutboxRelay::class)->poll($s->handle());
        expect($result[0]->problem)->toBe(ProblemCode::ContentHidden)->and($result[0]->status)->toBe('quarantine')->and($s->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
});

it('does not resurrect a previous incarnation when its event is replayed after explicit recreation', function (): void {
    $s = new OutboxScenario;
    try {
        $event = $s->event();
        $oldHandle = $s->handle();
        $s->append($event);
        $owner = $s->owner();
        $owner->transaction(fn () => new SurfaceInstances($owner)->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(1), sourceGeneration: new Revision(2), privacyGeneration: new Revision(2)));
        $next = SurfaceScenario::owner(app: $s->app, incarnation: 2);
        $next->transaction(fn () => new SurfaceInstances($next)->recreate(expectedDeletedRevision: new Revision(2), sourceGeneration: new Revision(3), privacyGeneration: new Revision(3)));
        $result = $s->app->make(OutboxRelay::class)->poll($oldHandle);
        expect($result[0]->problem)->toBe(ProblemCode::SurfaceIncarnationMismatch)->and($result[0]->status)->toBe('quarantine')->and($s->count())->toBe(0);
        expect((int) PgTarget::query(pdo: $s->db->owner, sql: 'SELECT incarnation FROM panel_a.driver_a_surface_instances')->fetchColumn())->toBe(2)
            ->and((int) PgTarget::query(pdo: $s->db->owner, sql: 'SELECT count(*) FROM panel_a.driver_a_tombstones')->fetchColumn())->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('quarantines repeatedly expired crash leases at the attempt budget before another consume', function (): void {
    $s = new OutboxScenario;
    try {
        $s->append($s->event());
        foreach ([1, 2, 3] as $attempt) {
            $lease = $s->store()->claim();
            expect($lease?->attempt)->toBe($attempt);
            usleep(1100000);
        }
        $result = $s->app->make(OutboxRelay::class)->poll($s->handle());
        expect($result[0]->status)->toBe('quarantine')->and($result[0]->reason)->toBe('outbox.attempts_exhausted')->and($s->consumer->calls)->toBe(0)->and($s->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
});
