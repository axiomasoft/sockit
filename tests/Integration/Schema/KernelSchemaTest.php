<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\DeliveryResult;
use SocKit\Operations\Internal\Receipts\ReceiptLedger;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Support\Ids;
use SocKit\Surfaces\Enums\SurfaceLifecycle;
use SocKit\Tests\Fixtures\Runtime\EnvelopeRace;
use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Fixtures\Runtime\SurfaceScenario;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('installs production component tables with exact catalog keys bounds checks and relay indexes', function (): void {
    $fixture = new SnapshotFixture;
    try {
        $fixture->migrations['a']->up();
        $physical = Scenario::object($fixture->descriptor()['physical']);
        $entities = Scenario::object($physical['entities']);
        $relations = array_column($fixture->catalog(), 'relname');
        $expected = Scenario::entries($fixture->descriptor()['relations']);
        sort($expected);
        expect($relations)->toBe($expected);
        $columns = PgTarget::query($fixture->pg->owner, "SELECT table_name,column_name,data_type,character_maximum_length,collation_name,is_nullable FROM information_schema.columns WHERE table_schema='panel_a'")->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($columns as $value) {
            $column = Scenario::object($value);
            $map[Scenario::text($column['table_name']).'.'.Scenario::text($column['column_name'])] = $column;
        }
        expect($map['driver_a_receipts.namespace']['character_maximum_length'])->toBe(384)
            ->and($map['driver_a_receipts.command_key']['character_maximum_length'])->toBe(128)
            ->and($map['driver_a_receipts.namespace']['collation_name'])->toBe('C')
            ->and($map['driver_a_receipts.outcome']['is_nullable'])->toBe('YES')
            ->and($map['driver_a_receipts.guard_context']['is_nullable'])->toBe('NO')
            ->and($map['driver_a_surface_instances.host_key']['character_maximum_length'])->toBe(512)
            ->and($map['driver_a_surface_instances.created_at']['data_type'])->toBe('timestamp with time zone')
            ->and($map['driver_a_outbox.deliveries']['data_type'])->toBe('jsonb');
        $constraints = PgTarget::query($fixture->pg->owner, "SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE connamespace='panel_a'::regnamespace")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($entities as $entity => $value) {
            $spec = Scenario::object($value);
            foreach (['checks', 'foreign_keys'] as $section) {
                foreach (Scenario::object($spec[$section]) as $key) {
                    expect($constraints)->toHaveKey(Scenario::text(Scenario::object($key)['name']));
                }
            }
            $primary = Scenario::object($spec['primary']);
            expect($constraints)->toHaveKey(Scenario::text($primary['name']));
            if ($entity === 'tombstones') {
                expect($constraints[Scenario::text($primary['name'])])->toBe('PRIMARY KEY (installation, panel, kind, host_type, host_key, incarnation)')
                    ->and($spec['key_policy'])->toBe('composite')
                    ->and($spec['foreign_keys'])->toBe([]);
            }
        }
        $outbox = Scenario::object($entities['outbox']);
        $relay = Scenario::object(Scenario::object($outbox['indexes'])['relay']);
        $query = $fixture->pg->owner->prepare('SELECT indexdef FROM pg_indexes WHERE schemaname=? AND indexname=?');
        $query->execute(['panel_a', $relay['name']]);
        expect($query->fetchColumn())->toContain('(installation, panel, kind, host_type, host_key, incarnation, event_id)');
        $id = Ids::uuid7();
        $fixture->connection()->table('panel_a.a_demo_targets')->insert(['id' => $id, 'installation' => 'host', 'panel' => 'acme.a', 'external_key' => '0042/Ü:Case']);
        $fixture->connection()->table('panel_a.a_demo_links')->insert(['id' => Ids::uuid7(), 'target_id' => $id]);
        expect($fixture->connection()->table('panel_a.a_demo_targets')->value('external_key'))->toBe('0042/Ü:Case')
            ->and($fixture->connection()->table('panel_a.a_demo_targets')->value('enabled'))->toBeTrue();
        expect(fn () => $fixture->connection()->table('panel_a.a_demo_targets')->where('id', $id)->delete())->toThrow(QueryException::class);
        $fixture->migrations['a']->down();
        expect($fixture->catalog())->toBe([]);
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions catalog_exact=true kernel_bounds=true composite_tombstone=true no_host_fk=true module_crud=true module_dependency_fk=true relay_index=true reverse_drop=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
});

it('runs actual P2 surface receipt envelope and durable outbox writes on the generated production schema', function (): void {
    $fixture = new SnapshotFixture;
    $runtime = null;
    try {
        $runtime = $fixture->envelope();
        $pdo = $fixture->pg->owner;
        $command = $runtime->command();
        $result = $runtime->execute($command);
        expect($result->isSuccess())->toBeTrue();
        $again = $runtime->execute($command);
        expect($again->isSuccess())->toBeTrue()->and($again->receiptId)->toBe($result->receiptId)
            ->and($runtime->mutation->writes)->toBe(1);
        expect((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_receipts')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, 'SELECT effects FROM panel_a.driver_envelope_state')->fetchColumn())->toBe(1)
            ->and(PgTarget::query($pdo, 'SELECT guard_context FROM panel_a.driver_a_receipts')->fetchColumn())->not->toBe('');
        $store = $runtime->base->store();
        $lease = $store->claim();
        expect($lease)->not->toBeNull();
        assert($lease !== null);
        expect($store->acknowledge(lease: $lease, result: DeliveryResult::confirmed()))->toBeTrue()
            ->and($store->claim())->toBeNull();
        $owner = $runtime->base->owner();
        $surface = new SurfaceInstances($owner);
        $deleted = $owner->transaction(fn () => $surface->transition(to: SurfaceLifecycle::Deleted, expectedRevision: new Revision(1), sourceGeneration: new Revision(2), privacyGeneration: new Revision(3)));
        expect($deleted->revision->value)->toBe(2)
            ->and((int) PgTarget::query($pdo, 'SELECT max_revision FROM panel_a.driver_a_tombstones')->fetchColumn())->toBe(2);
        $newOwner = SurfaceScenario::owner(app: $runtime->base->app, incarnation: 2);
        $new = $newOwner->transaction(fn () => new SurfaceInstances($newOwner)->recreate(expectedDeletedRevision: new Revision(2), sourceGeneration: new Revision(3), privacyGeneration: new Revision(4)));
        expect($new->ref->incarnation->value)->toBe(2)->and($new->id)->not->toBe($deleted->id)
            ->and((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_tombstones')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_b.other_b_receipts')->fetchColumn())->toBe(0);
        $fixture->migrations['a']->down();
        expect(array_column($fixture->catalog(), 'relname'))->not->toContain('driver_a_surface_instances', 'driver_a_receipts', 'driver_a_outbox', 'driver_a_tombstones');
        expect((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_b.other_b_surface_instances')->fetchColumn())->toBe(1);
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions public_envelope_confirmed=true replay_one_write=true guard_context_persisted=true outbox_claim_ack=true surface_recreate=true tombstone_retained=true panel_b_untouched=true'.PHP_EOL);
    } finally {
        if ($runtime !== null) {
            $runtime->base->db->close();
            $runtime->base->app->flush();
        }
        $fixture->cleanup();
    }
});

it('rolls back P2 receipt state revision and outbox together after a real owner failure', function (): void {
    $fixture = new SnapshotFixture;
    $runtime = null;
    try {
        $fixture->migrations['a']->up();
        $runtime = new OutboxScenario(install: false);
        SurfaceScenario::create($runtime->owner());
        $owner = $runtime->owner();
        $ledger = new ReceiptLedger($owner);
        $payload = ['value' => 1];
        $command = new CommandEnvelope(key: new CommandKey('p33.rollback.request'),
            namespace: new CommandNamespace(installation: $owner->handle()->actor->installation, panel: $owner->handle()->surface->panel, integration: 'acme.client', commandType: 'acme.runtime.mutate'),
            digest: PayloadDigest::of(commandType: 'acme.runtime.mutate', payload: $payload));
        $event = $runtime->event();
        expect(fn () => $ledger->transaction(envelope: $command, commandType: 'acme.runtime.mutate', payload: $payload, guardContext: 'p33.local.guard', write: function (string $receipt) use ($owner, $runtime, $event): never {
            new SurfaceInstances($owner)->revise(expectedRevision: new Revision(1), sourceGeneration: new Revision(1), privacyGeneration: new Revision(1));
            $runtime->store()->append(event: $event, consumers: ['acme.consumer']);
            throw new RuntimeException('injected.owner.failure');
        }))->toThrow(RuntimeException::class, 'injected.owner.failure');
        expect((int) PgTarget::query($fixture->pg->owner, 'SELECT revision FROM panel_a.driver_a_surface_instances')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($fixture->pg->owner, 'SELECT count(*) FROM panel_a.driver_a_receipts')->fetchColumn())->toBe(0)
            ->and((int) PgTarget::query($fixture->pg->owner, 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(0);
        fwrite(STDERR, 'P3.3 SQL assertions receipt_surface_outbox_atomic_rollback=true'.PHP_EOL);
    } finally {
        if ($runtime !== null) {
            $runtime->db->close();
            $runtime->app->flush();
        }
        $fixture->cleanup();
    }
});

it('arbitrates unique receipt claims with independent processes on production DDL', function (string $mode): void {
    $fixture = new SnapshotFixture;
    $runtime = null;
    try {
        $runtime = $fixture->envelope();
        $results = EnvelopeRace::run(s: $runtime, mode: $mode);
        expect($results[1]->isSuccess())->toBeTrue();
        if ($mode === 'duplicate') {
            expect($results[0]->toWire())->toBe($results[1]->toWire());
        } else {
            expect($results[0]->isSuccess())->toBeFalse();
        }
        $pdo = $fixture->pg->owner;
        expect((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_receipts')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, 'SELECT effects FROM panel_a.driver_envelope_state')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, 'SELECT revision FROM panel_a.driver_envelope_revisions')->fetchColumn())->toBe(2)
            ->and((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn())->toBe(1);
        fwrite(STDERR, 'P3.3 SQL assertions production_unique_claim='.$mode.' one_receipt=true one_effect=true one_outbox=true'.PHP_EOL);
    } finally {
        if ($runtime !== null) {
            $runtime->base->db->close();
            $runtime->base->app->flush();
        }
        $fixture->cleanup();
    }
})->with(['duplicate', 'rollback']);

it('enforces DTO byte bounds UUID7 lifecycle and nullable in-flight receipt constraints in PostgreSQL', function (): void {
    $fixture = new SnapshotFixture;
    try {
        $fixture->migrations['a']->up();
        $connection = $fixture->connection();
        $surface = $connection->table('panel_a.a_surface_instances');
        $valid = ['id' => Ids::uuid7(), 'installation' => 'host', 'panel' => 'acme.a', 'kind' => 'acme.runtime', 'host_type' => 'article',
            'host_key' => str_repeat('Я', 256), 'incarnation' => 1, 'lifecycle' => 'live', 'revision' => 1, 'source_generation' => 1, 'privacy_generation' => 1,
            'created_at' => '2026-10-29 00:00:00+00', 'updated_at' => '2026-10-29 00:00:00+00'];
        $surface->insert($valid);
        expect($surface->value('host_key'))->toBe($valid['host_key']);
        foreach ([['host_key' => str_repeat('Я', 256).'x'], ['host_key' => ''], ['kind' => str_repeat('k', 97)], ['revision' => 0],
            ['incarnation' => 0], ['lifecycle' => 'unknown'], ['lifecycle' => 'deleted'], ['deleted_at' => '2026-10-29 00:00:00+00'],
            ['id' => '00000000-0000-4000-8000-000000000042']] as $invalid) {
            expect(fn () => $connection->table('panel_a.a_surface_instances')->update($invalid))->toThrow(QueryException::class);
        }
        $receipt = ['id' => Ids::uuid7(), 'namespace' => str_repeat('a', 384), 'command_key' => str_repeat('K', 128), 'digest' => 'sha256:'.str_repeat('0', 64),
            'actor_ref' => 'user:0042', 'actor_kind' => 'person', 'guard_context' => '{"fence":"local"}'];
        $connection->table('panel_a.a_receipts')->insert($receipt);
        expect($connection->table('panel_a.a_receipts')->value('outcome'))->toBeNull();
        foreach ([['namespace' => str_repeat('a', 385)], ['command_key' => str_repeat('K', 129)], ['actor_kind' => 'anonymous'], ['digest' => 'invalid'],
            ['outcome' => 'pending'], ['outcome' => 'confirmed'], ['outcome' => 'rejected'], ['guard_context' => null], ['revision' => 0]] as $invalid) {
            expect(fn () => $connection->table('panel_a.a_receipts')->update($invalid))->toThrow(QueryException::class);
        }
        $connection->table('panel_a.a_receipts')->update(['outcome' => 'rejected', 'problem' => 'sockit.revision_conflict']);
        expect($connection->table('panel_a.a_receipts')->value('outcome'))->toBe('rejected');
        $outbox = [...array_intersect_key($valid, array_flip(['installation', 'panel', 'kind', 'host_type', 'host_key', 'incarnation'])),
            'id' => Ids::uuid7(), 'event_id' => Ids::uuid7(), 'source_generation' => str_repeat('я', 32), 'digest' => $receipt['digest'], 'envelope' => '{}', 'deliveries' => '{}'];
        $connection->table('panel_a.a_outbox')->insert($outbox);
        foreach ([['deliveries' => '[]'], ['source_generation' => str_repeat('я', 33)]] as $invalid) {
            expect(fn () => $connection->table('panel_a.a_outbox')->update($invalid))->toThrow(QueryException::class);
        }
        expect($connection->table('panel_a.a_surface_instances')->value('revision'))->toBe(1);
        fwrite(STDERR, 'P3.3 SQL assertions host_key_512_bytes_lossless=true host_key_513_bytes_denied=true namespace_384_key_128=true uuid7_lifecycle_checks=true in_flight_null_allowed=true invalid_stable_outcomes_denied=true outbox_json_object_and_byte_bounds=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
});

it('materializes the selected module version history in a new initial snapshot', function (): void {
    $fixture = new SnapshotFixture(version: 2);
    try {
        $fixture->migrations['a']->up();
        $fixture->connection()->table('panel_a.a_demo_targets')->insert(['id' => Ids::uuid7(), 'installation' => 'host', 'panel' => 'acme.a', 'external_key' => 'version2', 'label' => "literal ' / \\ label"]);
        expect($fixture->connection()->table('panel_a.a_demo_targets')->value('label'))->toBe("literal ' / \\ label");
        $fixture->migrations['a']->down();
        expect($fixture->catalog())->toBe([]);
        fwrite(STDERR, 'P3.3 SQL assertions selected_module_v2_initial_up_down=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
});
