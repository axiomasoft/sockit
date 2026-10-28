<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Foundation\Application;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\Internal\Storage\SurfaceInstances;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Tests\Integration\Support\PgTarget;

final class SurfaceScenario
{
    public static function owner(Application $app, string $panel = 'acme.a', int $incarnation = 1): OwnerConnection
    {
        $ref = RuntimeScenario::surface($panel);
        $ref = new SurfaceRef(panel: $ref->panel, kind: $ref->kind, host: $ref->host, incarnation: new Incarnation($incarnation));

        return $app->make(OwnerStorage::class)->forHandle($app->make(OperationHandles::class)->forSurface($ref));
    }

    public static function install(RuntimeDatabase $db): void
    {
        $db->install();
        foreach (['panel_a.driver_a_', 'panel_b.other_b_'] as $prefix) {
            foreach (['surface_instances', 'tombstones'] as $entity) {
                $db->owner->exec('DROP TABLE '.$prefix.$entity);
            }
            $identity = 'installation varchar(64) COLLATE "C" NOT NULL, panel varchar(96) COLLATE "C" NOT NULL, kind varchar(96) COLLATE "C" NOT NULL, host_type varchar(96) COLLATE "C" NOT NULL, host_key varchar(512) COLLATE "C" NOT NULL';
            // Short, test-only constraints; production snapshot DDL belongs to P3.
            $db->owner->exec('CREATE TABLE '.$prefix.'surface_instances ('.$identity.', id uuid PRIMARY KEY, incarnation bigint NOT NULL CHECK (incarnation >= 1), lifecycle text NOT NULL CHECK (lifecycle IN (\'live\',\'archived\',\'deleted\')), revision bigint NOT NULL CHECK (revision >= 1), source_generation bigint NOT NULL CHECK (source_generation >= 1), privacy_generation bigint NOT NULL CHECK (privacy_generation >= 1), created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, deleted_at timestamptz, UNIQUE (installation,panel,kind,host_type,host_key), CHECK ((lifecycle = \'deleted\') = (deleted_at IS NOT NULL)))');
            $db->owner->exec('CREATE TABLE '.$prefix.'tombstones ('.$identity.', incarnation bigint NOT NULL CHECK (incarnation >= 1), surface_id uuid NOT NULL, max_revision bigint NOT NULL CHECK (max_revision >= 1), source_generation bigint NOT NULL CHECK (source_generation >= 1), deleted_at timestamptz NOT NULL, PRIMARY KEY (installation,panel,kind,host_type,host_key,incarnation))');
        }
        $db->queries = [];
        fwrite(STDERR, "P2.4 test-only DDL installed AFTER verified PDO identities\n");
    }

    public static function create(OwnerConnection $owner): void
    {
        $owner->transaction(fn (OwnerConnection $connection) => new SurfaceInstances($connection)->create(sourceGeneration: new Revision(1), privacyGeneration: new Revision(1)));
    }

    /** Real processes running the same repository. The first holds the surface before the second reaches it.
     * @return list<string> committed or a stable problem code
     */
    public static function race(RuntimeDatabase $db, string $holder, string $waiter): array
    {
        $processes = [];
        $streams = [];
        $pids = [];
        try {
            foreach ([$holder, $waiter] as $index => $action) {
                $process = proc_open([PHP_BINARY, __DIR__.'/surface-child.php', $action, $index === 0 ? 'hold' : 'wait'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Cannot start surface contender');
                }
                $processes[$index] = $process;
                $streams[$index] = $pipes;
                stream_set_timeout($pipes[1], 10);
                $pids[$index] = (int) fgets($pipes[1]);
                if ($index === 0) {
                    expect(trim((string) fgets($pipes[1])))->toBe('locked');
                }
            }
            expect($pids[0])->toBeGreaterThan(0)->and($pids[1])->toBeGreaterThan(0)->and($pids[0])->not->toBe($pids[1]);
            $deadline = microtime(true) + 5;
            do {
                $query = $db->owner->prepare('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?');
                $query->execute([$pids[1]]);
                $blocked = $query->fetchColumn() === 'Lock';
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            fwrite(STDERR, 'P2.4 barrier '.$holder.' vs '.$waiter.' holder='.$pids[0].' waiter='.$pids[1].' wait_event_type='.($blocked ? 'Lock' : 'UNOBSERVED').PHP_EOL);
            expect($blocked)->toBeTrue();
            fwrite($streams[0][0], "release\n");
            fflush($streams[0][0]);
            $results = [];
            foreach ($processes as $index => $process) {
                $results[] = trim((string) stream_get_contents($streams[$index][1]));
                $error = stream_get_contents($streams[$index][2]);
                foreach ($streams[$index] as $pipe) {
                    fclose($pipe);
                }
                expect(proc_close($process))->toBe(0, (string) $error);
                unset($processes[$index]);
                fwrite(STDERR, (string) $error);
            }
            fwrite(STDERR, 'P2.4 race results '.json_encode($results, JSON_THROW_ON_ERROR).PHP_EOL);

            return $results;
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    public static function decoyUntouched(RuntimeDatabase $db): void
    {
        expect(PgTarget::query($db->decoy, "SELECT payload FROM panel_a.driver_a_receipts WHERE id='42'")->fetchColumn())->toBe('decoy-sentinel');
        expect((int) PgTarget::query($db->decoy, 'SELECT count(*) FROM panel_a.driver_a_surface_instances')->fetchColumn())->toBe(0);
    }
}
