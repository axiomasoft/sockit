<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Foundation\Application;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Internal\Receipts\ReceiptLedger;
use SocKit\Operations\Internal\Storage\OwnerConnection;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Tests\Integration\Support\PgTarget;

final class ReceiptScenario
{
    public const string TYPE = 'acme.runtime.mutate';

    public static function install(RuntimeDatabase $db): void
    {
        $db->install();
        foreach (['panel_a.driver_a_', 'panel_b.other_b_'] as $prefix) {
            $db->owner->exec('DROP TABLE '.$prefix.'receipts');
            // Frozen test-only snapshot, no production DDL. Indexed identity is exact and lossless.
            $db->owner->exec('CREATE TABLE '.$prefix.'receipts (id uuid PRIMARY KEY, namespace varchar(384) COLLATE "C" NOT NULL, command_key varchar(128) COLLATE "C" NOT NULL, digest varchar(71) NOT NULL, actor_ref text NOT NULL, actor_kind text NOT NULL, on_behalf_of text, outcome text CHECK (outcome IN (\'confirmed\',\'rejected\')), resource jsonb, revision bigint CHECK (revision >= 1), problem text, UNIQUE (namespace, command_key))');
        }
        $db->queries = [];
        fwrite(STDERR, "P2.5 receipt fixture DDL AFTER verified private PDO identities\n");
    }

    /** @param array<mixed> $payload */
    public static function envelope(string $panel = 'acme.a', string $installation = 'runtime-test', string $integration = 'acme.client', string $key = 'req.2026-10-28.0001', array $payload = ['value' => 1], string $type = self::TYPE): CommandEnvelope
    {
        return new CommandEnvelope(key: new CommandKey($key), namespace: new CommandNamespace(installation: new InstallationRef($installation), panel: RuntimeScenario::surface($panel)->panel, integration: $integration, commandType: $type), digest: PayloadDigest::of(commandType: $type, payload: $payload));
    }

    public static function owner(Application $app, string $panel = 'acme.a'): OwnerConnection
    {
        return $app->make(OwnerStorage::class)->forHandle($app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface($panel)));
    }

    public static function ledger(Application $app, string $panel = 'acme.a'): ReceiptLedger
    {
        return new ReceiptLedger(self::owner(app: $app, panel: $panel));
    }

    /** A real local fixture effect, not a social operation. */
    public static function effect(OwnerConnection $owner, string $id): OperationResult
    {
        $owner->table('surface_instances')->insert(['id' => $id, 'payload' => 'test-only-effect']);

        return OperationResult::confirmed(receiptId: $id, resource: new ResourceRef(panel: $owner->handle()->surface->panel, kind: ResourceKind::SurfaceInstance, id: $id), revision: new Revision(1));
    }

    /** @return list<array<string, mixed>> */
    public static function race(RuntimeDatabase $db, string $mode): array
    {
        $processes = $streams = $pids = [];
        try {
            foreach (['hold', 'wait'] as $index => $role) {
                $process = proc_open([PHP_BINARY, __DIR__.'/receipt-child.php', $role, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Cannot start receipt contender');
                }
                $processes[$index] = $process;
                $streams[$index] = $pipes;
                stream_set_timeout($pipes[1], 10);
                $pids[$index] = (int) fgets($pipes[1]);
                if ($index === 0) {
                    expect(trim((string) fgets($pipes[1])))->toBe('claimed');
                }
            }
            expect($pids[0])->toBeGreaterThan(0)->and($pids[1])->toBeGreaterThan(0)->and($pids[0])->not->toBe($pids[1]);
            $deadline = microtime(true) + 5;
            do {
                $query = $db->owner->prepare('SELECT wait_event_type, pg_blocking_pids(pid) @> ARRAY[?]::int[] AS blocked_by_holder FROM pg_stat_activity WHERE pid = ?');
                $query->execute([$pids[0], $pids[1]]);
                $row = $query->fetch(\PDO::FETCH_ASSOC);
                $blocked = is_array($row) && $row['wait_event_type'] === 'Lock' && $row['blocked_by_holder'] === true;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            fwrite(STDERR, 'P2.5 barrier mode='.$mode.' holder='.$pids[0].' waiter='.$pids[1].' wait_event_type='.($blocked ? 'Lock, blocked by holder' : 'UNOBSERVED').PHP_EOL);
            expect($blocked)->toBeTrue();
            fwrite($streams[0][0], "release\n");
            fflush($streams[0][0]);
            $results = [];
            foreach ($processes as $index => $process) {
                $output = (string) stream_get_contents($streams[$index][1]);
                $error = (string) stream_get_contents($streams[$index][2]);
                foreach ($streams[$index] as $pipe) {
                    fclose($pipe);
                }
                expect(proc_close($process))->toBe(0, $error);
                unset($processes[$index]);
                $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($result)) {
                    throw new \RuntimeException('Invalid race result');
                }
                $validated = [];
                foreach ($result as $key => $value) {
                    if (! is_string($key)) {
                        throw new \RuntimeException('Invalid receipt result key');
                    }
                    $validated[$key] = $value;
                }
                $results[] = $validated;
                fwrite(STDERR, $error);
            }
            fwrite(STDERR, 'P2.5 race results '.json_encode($results, JSON_THROW_ON_ERROR).PHP_EOL);

            return $results;
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    public static function counts(RuntimeDatabase $db, int $effects, int $receipts): void
    {
        expect((int) PgTarget::query($db->owner, 'SELECT count(*) FROM panel_a.driver_a_surface_instances')->fetchColumn())->toBe($effects)
            ->and((int) PgTarget::query($db->owner, 'SELECT count(*) FROM panel_a.driver_a_receipts')->fetchColumn())->toBe($receipts)
            ->and((int) PgTarget::query($db->owner, 'SELECT count(*) FROM panel_a.driver_a_receipts WHERE outcome IS NULL')->fetchColumn())->toBe(0)
            ->and(PgTarget::query($db->decoy, "SELECT payload FROM panel_a.driver_a_receipts WHERE id='42'")->fetchColumn())->toBe('decoy-sentinel');
        fwrite(STDERR, 'P2.5 SQL assertions owner effects='.$effects.' receipts='.$receipts.' unfinished=0 decoy=sentinel'.PHP_EOL);
    }
}
