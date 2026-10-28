<?php

declare(strict_types=1);

use Illuminate\Database\PostgresConnection;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\SnapshotRenderer;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

it('executes the old frozen file in fresh processes without current helpers or modules after config changes', function (): void {
    $fixture = new SnapshotFixture(explicitSchema: false);
    try {
        $source = $fixture->sources['a'];
        $descriptor = $fixture->descriptor();
        $draft = Scenario::object($descriptor['schema']);
        $components = Scenario::object($draft['components']);
        expect(Scenario::object($components['acme.demo-targets'])['version'])->toBe(1)
            ->and($fixture->compile(version: 2)->digest)->not->toBe($draft['digest'])
            ->and($fixture->compile(selected: false)->digest)->not->toBe($draft['digest'])
            ->and($source)->not->toContain('SocKit\\', 'Schema::', 'config(', 'DROP SCHEMA', ' CASCADE');
        $physical = Scenario::object($descriptor['physical']);
        expect(Scenario::object($physical['location'])['schema'])->toBe('panel_a');
        $fixture->migrations['b']->up();
        $b = $fixture->catalog('b');
        foreach (['up', 'down'] as $action) {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Schema/snapshot-child.php', $fixture->paths['a'], $action]);
            $process->setTimeout(15);
            $process->run();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
                ->and($process->getOutput())->toContain('fresh_snapshot_'.$action.'=ok', 'latest_helpers_unavailable=true');
            fwrite(STDERR, $process->getOutput().$process->getErrorOutput());
            expect($fixture->catalog('b'))->toBe($b);
            if ($action === 'up') {
                $fixture->pg->owner->exec("INSERT INTO panel_a.driver_a_demo_targets (id,installation,panel,external_key) VALUES ('019a1ba0-0000-7000-8000-000000000042','host','acme.a','old-module-crud')");
                expect(PgTarget::query($fixture->pg->owner, 'SELECT external_key FROM panel_a.driver_a_demo_targets')->fetchColumn())->toBe('old-module-crud');
                $fixture->pg->owner->exec('CREATE TABLE panel_a.later_independent (sentinel text)');
                $fixture->pg->owner->exec("INSERT INTO panel_a.later_independent VALUES ('later-data')");
            }
        }
        expect(array_column($fixture->catalog(), 'relname'))->toBe(['later_independent'])
            ->and(PgTarget::query($fixture->pg->owner, 'SELECT sentinel FROM panel_a.later_independent')->fetchColumn())->toBe('later-data')
            ->and(file_get_contents($fixture->paths['a']))->toBe($source);
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions frozen_module_version=1 old_module_removed=true panel_config_removed=true frozen_schema=panel_a frozen_prefix=driver_a_ panel_b_host_decoy_preserved=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
});

it('renders deterministic canonical source and rejects corrupted descriptor encoding or digest before DDL', function (string $damage): void {
    $fixture = new SnapshotFixture;
    $path = null;
    try {
        $source = $fixture->sources['a'];
        $same = new SnapshotRenderer()->render(schema: $fixture->schemas['a'], connection: $fixture->connection(), deployment: $fixture->deployment());
        expect($same)->toBe($source);
        $snapshot = $fixture->migrations['a']->constant('SNAPSHOT');
        $digest = $fixture->migrations['a']->constant('DIGEST');
        $changed = $damage === 'encoding' ? str_replace($snapshot, '%'.$snapshot, $source) : str_replace($digest, str_repeat('0', 64), $source);
        $path = SnapshotFixture::file($changed);
        expect(fn () => SnapshotFixture::load($path)->up())->toThrow(RuntimeException::class, 'SNAPSHOT_INTEGRITY_INVALID');
        expect($fixture->catalog())->toBe([]);
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions integrity_'.$damage.'_refused_before_ddl=true'.PHP_EOL);
    } finally {
        if ($path !== null) {
            unlink($path);
        }
        $fixture->cleanup();
    }
})->with(['encoding', 'digest']);

it('refuses redirected aliases and unsupported drivers without touching either physical target', function (string $change): void {
    $fixture = new SnapshotFixture;
    try {
        $fixture->migrations['a']->up();
        $before = $fixture->catalog();
        $config = Scenario::object($fixture->connection()->getConfig());
        $config = match ($change) {
            'database' => [...$config, 'database' => PgTarget::fromEnvironment()->decoyConfiguration()['database']],
            'endpoint' => [...$config, 'host' => '192.0.2.9'],
            'driver' => [...$config, 'driver' => 'sqlite', 'database' => ':memory:'],
            'routing' => [...$config, 'read' => ['host' => '127.0.0.1'], 'write' => ['host' => '127.0.0.1']],
            'pdo' => $config,
            default => throw new RuntimeException('Unknown alias fixture'),
        };
        $fixture->capsule->getDatabaseManager()->purge('owner_a');
        $fixture->capsule->addConnection(config: $config, name: 'owner_a');
        if ($change === 'pdo') {
            $fixture->connection()->setPdo($fixture->pg->decoy);
        }
        $problem = match ($change) {
            'driver' => 'SNAPSHOT_DRIVER_UNSUPPORTED', 'routing' => 'SNAPSHOT_ENDPOINT_AMBIGUOUS', default => 'SNAPSHOT_CONNECTION_CHANGED',
        };
        expect(fn () => $fixture->migrations['a']->down())->toThrow(RuntimeException::class, $problem);
        expect($fixture->catalog())->toBe($before);
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions alias_change='.$change.' refused_before_write=true owner_and_decoy_preserved=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
})->with(['database', 'endpoint', 'driver', 'routing', 'pdo']);

it('allows credential rotation while preserving the frozen selector and physical namespace', function (): void {
    $fixture = new SnapshotFixture;
    try {
        $fixture->migrations['a']->up();
        $connection = $fixture->connection();
        $config = Scenario::object($connection->getConfig());
        // Simulate credential rotation on a verified, already authenticated PDO.
        $rotated = new PostgresConnection(pdo: $connection->getPdo(), database: Scenario::text($config['database']), tablePrefix: 'new_prefix_', config: [...$config, 'password' => 'rotated-secret']);
        $fixture->capsule->getDatabaseManager()->extend(name: 'owner_a', resolver: fn () => $rotated);
        $fixture->capsule->getDatabaseManager()->purge('owner_a');
        $fixture->migrations['a']->down();
        expect($fixture->catalog())->toBe([])->and($fixture->sources['a'])->not->toContain('rotated-secret', Scenario::text($config['password']));
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions credential_rotation_accepted=true prefix_change_uses_frozen_tables=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
});

it('does not adopt unmanaged tables or indexes and atomically leaves earlier tables absent', function (string $object): void {
    $fixture = new SnapshotFixture;
    try {
        if ($object === 'table') {
            $fixture->pg->owner->exec('CREATE TABLE panel_a.driver_a_tombstones (sentinel text)');
            $fixture->pg->owner->exec("INSERT INTO panel_a.driver_a_tombstones VALUES ('unmanaged')");
        } else {
            $relations = Scenario::entries($fixture->descriptor()['relations']);
            $index = array_values(array_filter($relations, fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'sk_idx_')))[0];
            $fixture->pg->owner->exec('CREATE INDEX "'.$index.'" ON public.p33_host (value)');
            // Index names collide only in the same schema; make a same-schema unmanaged relation.
            $fixture->pg->owner->exec('CREATE TABLE panel_a."'.$index.'" (sentinel text)');
        }
        $before = $fixture->catalog();
        expect(fn () => $fixture->migrations['a']->up())->toThrow(RuntimeException::class, 'SNAPSHOT_UNMANAGED_OBJECT');
        expect($fixture->catalog())->toBe($before);
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions unmanaged_'.$object.'_not_adopted=true partial_up_absent=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
})->with(['table', 'index']);

it('rolls back a middle DDL failure including earlier tables indexes and ownership metadata', function (bool $outerTransaction): void {
    $fixture = new SnapshotFixture;
    $path = null;
    try {
        $snapshot = $fixture->descriptor();
        $program = Scenario::object($snapshot['program']);
        $up = Scenario::entries($program['up']);
        array_splice($up, 3, 0, ['SELECT 1/0']);
        $program['up'] = $up;
        $snapshot['program'] = $program;
        // Fault injection changes the literal program AND its integrity digest, not validation logic.
        $json = SchemaValues::json($snapshot);
        $encoded = $fixture->migrations['a']->constant('SNAPSHOT');
        $digest = $fixture->migrations['a']->constant('DIGEST');
        $path = SnapshotFixture::file(strtr($fixture->sources['a'], [$encoded => base64_encode($json), $digest => hash('sha256', $json)]));
        $connection = $fixture->connection();
        if ($outerTransaction) {
            $connection->beginTransaction();
            $connection->statement("INSERT INTO public.p33_host VALUES ('outer-survives')");
        }
        expect(fn () => SnapshotFixture::load($path)->up())->toThrow(RuntimeException::class, 'SNAPSHOT_DDL_FAILED');
        expect($fixture->catalog())->toBe([])->and($connection->scalar('SELECT 42'))->toBe(42);
        if ($outerTransaction) {
            expect($connection->transactionLevel())->toBe(1)
                ->and($connection->scalar("SELECT count(*) FROM public.p33_host WHERE value='outer-survives'"))->toBe(1);
            $connection->rollBack();
        }
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions mid_up_failure_atomic=true outer_transaction='.($outerTransaction ? 'savepoint_preserved' : 'none').' owned_catalog_empty=true'.PHP_EOL);
    } finally {
        if ($path !== null) {
            unlink($path);
        }
        $fixture->cleanup();
    }
})->with([false, true]);

it('refuses rollback of changed or replaced owned objects before deleting any table', function (string $change): void {
    $fixture = new SnapshotFixture;
    try {
        $fixture->migrations['a']->up();
        $sql = match ($change) {
            'column' => 'ALTER TABLE panel_a.driver_a_demo_targets ADD COLUMN later_data text',
            'index' => 'CREATE INDEX later_index ON panel_a.driver_a_demo_targets (external_key)',
            'receipt' => "COMMENT ON TABLE panel_a.driver_a_demo_targets IS 'unmanaged'",
            'sequence' => 'CREATE SEQUENCE panel_a.later_sequence OWNED BY panel_a.driver_a_demo_targets.external_key',
            'replacement' => 'ALTER TABLE panel_a.driver_a_outbox RENAME TO old_outbox',
            default => throw new RuntimeException('Unknown ownership fixture'),
        };
        $fixture->pg->owner->exec($sql);
        if ($change === 'replacement') {
            $fixture->pg->owner->exec('CREATE TABLE panel_a.driver_a_outbox (LIKE panel_a.old_outbox INCLUDING ALL)');
            $receipt = PgTarget::query($fixture->pg->owner, "SELECT obj_description('panel_a.old_outbox'::regclass)")->fetchColumn();
            $fixture->pg->owner->exec('COMMENT ON TABLE panel_a.driver_a_outbox IS '.$fixture->pg->owner->quote(Scenario::text($receipt)));
        }
        $before = $fixture->catalog();
        expect(fn () => $fixture->migrations['a']->down())->toThrow(RuntimeException::class, 'SNAPSHOT_OWNERSHIP_CHANGED');
        expect($fixture->catalog())->toBe($before);
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions rollback_change='.$change.' refused_before_drop=true all_tables_preserved=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
})->with(['column', 'index', 'receipt', 'sequence', 'replacement']);

it('preserves later dependent tables and rolls back preceding reverse steps when DROP RESTRICT fails', function (): void {
    $fixture = new SnapshotFixture;
    try {
        $fixture->migrations['a']->up();
        $fixture->migrations['b']->up();
        $fixture->pg->owner->exec('CREATE TABLE panel_a.later_owned_table (id uuid REFERENCES panel_a.driver_a_surface_instances(id))');
        $before = $fixture->catalog();
        $b = $fixture->catalog('b');
        expect(fn () => $fixture->migrations['a']->down())->toThrow(RuntimeException::class, 'SNAPSHOT_DDL_FAILED');
        expect($fixture->catalog())->toBe($before)->and($fixture->catalog('b'))->toBe($b);
        $fixture->pg->owner->exec('DROP TABLE panel_a.later_owned_table');
        $fixture->migrations['a']->down();
        expect($fixture->catalog())->toBe([])->and($fixture->catalog('b'))->toBe($b);
        $fixture->untouched();
        fwrite(STDERR, 'P3.3 SQL assertions descendant_blocks_rollback=true reverse_failure_atomic=true later_tables_preserved=true panel_b_host_decoy_preserved=true'.PHP_EOL);
    } finally {
        $fixture->cleanup();
    }
});
