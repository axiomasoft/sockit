<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Integration\Support\TestSchema;

it('rejects unsafe targets before connecting', function (): void {
    $data = ['run' => '0123456789abcdef', 'container' => str_repeat('a', 64), 'host' => 'prod.example', 'port' => 5432, 'owner' => 'production', 'decoy' => 'other', 'user' => 'postgres', 'password' => ''];
    expect(fn () => new PgTarget($data))->toThrow(RuntimeException::class, 'Unsafe PostgreSQL test target');
});

it('isolates schemas and decoy and rolls back after a fault', function (): void {
    $target = PgTarget::fromEnvironment();
    $owner = $target->connect();
    $decoy = $target->connect(decoy: true);
    $capsule = new Capsule;
    $capsule->addConnection($target->decoyConfiguration());
    expect(PgTarget::query($capsule->getConnection()->getPdo(), 'SELECT current_database()')->fetchColumn())->toBe(PgTarget::query($decoy, 'SELECT current_database()')->fetchColumn());
    try {
        TestSchema::install($owner, 'panel_a');
        TestSchema::install($owner, 'panel_b');
        $decoy->exec('CREATE TABLE public.sentinel (id text PRIMARY KEY)');
        $decoy->exec("INSERT INTO public.sentinel VALUES ('untouched')");
        $owner->exec("INSERT INTO panel_b.acme_runtime_state VALUES ('b', 'untouched')");
        try {
            $owner->beginTransaction();
            $owner->exec("INSERT INTO panel_a.acme_runtime_state VALUES ('x', 'changed')");
            $owner->exec("INSERT INTO panel_a.acme_runtime_revisions VALUES ('x', 1)");
            throw new RuntimeException('injected fault');
        } catch (RuntimeException $e) {
            $owner->rollBack();
            expect($e->getMessage())->toBe('injected fault');
        }
        expect((int) PgTarget::query($owner, 'SELECT count(*) FROM panel_a.acme_runtime_state')->fetchColumn())->toBe(0)
            ->and((int) PgTarget::query($owner, 'SELECT count(*) FROM panel_a.acme_runtime_revisions')->fetchColumn())->toBe(0)
            ->and(PgTarget::query($owner, "SELECT value FROM panel_b.acme_runtime_state WHERE id='b'")->fetchColumn())->toBe('untouched')
            ->and($capsule->getConnection()->table('sentinel')->value('id'))->toBe('untouched');
    } finally {
        TestSchema::cleanup($owner, 'panel_a');
        TestSchema::cleanup($owner, 'panel_b');
        $decoy->exec('DROP TABLE IF EXISTS public.sentinel');
    }
    expect(PgTarget::query($owner, "SELECT to_regnamespace('panel_a')")->fetchColumn())->toBeNull();
});

it('observes two child backends blocked on a row lock', function (): void {
    $owner = PgTarget::fromEnvironment()->connect();
    TestSchema::install($owner, 'panel_a');
    $processes = [];
    $streams = [];
    try {
        $owner->exec("INSERT INTO panel_a.acme_runtime_state VALUES ('lock', 'parent')");
        foreach (['holder', 'waiter'] as $mode) {
            $process = proc_open([PHP_BINARY, __DIR__.'/../../Support/lock-child.php', $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start child');
            }
            $processes[] = $process;
            $streams[] = $pipes;
            stream_set_timeout($pipes[1], 5);
            $pids[] = (int) fgets($pipes[1]);
            if ($mode === 'holder') {
                expect(trim((string) fgets($pipes[1])))->toBe('locked');
            }
        }
        expect($pids[0])->toBeGreaterThan(0);
        expect($pids[1])->toBeGreaterThan(0);
        expect(count(array_unique([$pids[0], $pids[1], (int) PgTarget::query($owner, 'SELECT pg_backend_pid()')->fetchColumn()])))->toBe(3);
        $deadline = microtime(true) + 5;
        $blocked = false;
        do {
            $query = $owner->prepare("SELECT CASE WHEN wait_event_type = 'Lock' THEN 1 ELSE 0 END FROM pg_stat_activity WHERE pid = ?");
            $query->execute([$pids[1]]);
            $blocked = (int) $query->fetchColumn() === 1;
            if (! $blocked) {
                usleep(10000);
            }
        } while (! $blocked && microtime(true) < $deadline);
        fwrite(STDERR, 'PG barrier holder='.$pids[0].' waiter='.$pids[1].' wait_event_type='.($blocked ? 'Lock' : 'unobserved').PHP_EOL);
        expect($blocked)->toBeTrue();
        fwrite($streams[0][0], "release\n");
        fflush($streams[0][0]);
        foreach ($processes as $index => $process) {
            expect(trim((string) stream_get_contents($streams[$index][1])))->toBe('committed');
            $error = stream_get_contents($streams[$index][2]);
            foreach ($streams[$index] as $pipe) {
                fclose($pipe);
            }
            expect(proc_close($process))->toBe(0, (string) $error);
            unset($processes[$index]);
        }
        expect(PgTarget::query($owner, "SELECT value FROM panel_a.acme_runtime_state WHERE id='lock'")->fetchColumn())->toBe('child');
    } finally {
        foreach ($processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        TestSchema::cleanup($owner, 'panel_a');
    }
});

it('rejects a missing database marker before writes', function (): void {
    $target = PgTarget::fromEnvironment();
    $owner = $target->connect();
    $database = PgTarget::query($owner, 'SELECT current_database()')->fetchColumn();
    $marker = PgTarget::query($owner, "SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = current_database()")->fetchColumn();
    if (! is_string($database) || ! preg_match('/^sockit_p2_[a-f0-9]{16}_test$/D', $database) || ! is_string($marker) || ! preg_match('/^sockit-p21-[a-f0-9]{16}$/D', $marker)) {
        throw new RuntimeException('Unexpected owned database identity');
    }
    try {
        $owner->exec('COMMENT ON DATABASE "'.$database.'" IS NULL');
        expect(fn () => $target->connect())->toThrow(RuntimeException::class, 'database identity mismatch');
        expect(PgTarget::query($owner, "SELECT to_regnamespace('panel_a')")->fetchColumn())->toBeNull();
    } finally {
        $owner->exec('COMMENT ON DATABASE "'.$database."\" IS '".$marker."'");
    }
});

it('cleans up its test schema after an exception escapes the fixture', function (): void {
    $owner = PgTarget::fromEnvironment()->connect();
    try {
        try {
            TestSchema::install($owner, 'panel_a');
            throw new RuntimeException('fixture failure');
        } finally {
            TestSchema::cleanup($owner, 'panel_a');
        }
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('fixture failure');
    }
    expect(PgTarget::query($owner, "SELECT to_regnamespace('panel_a')")->fetchColumn())->toBeNull();
});
