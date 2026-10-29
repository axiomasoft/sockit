<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Tests\Unit\Audience\Support\Scenario;

final class ProjectionRace
{
    public static function run(ReactionScenario $s, string $mode): void
    {
        $processes = $streams = $pids = [];
        try {
            foreach (['hold', 'wait'] as $i => $role) {
                $process = proc_open([PHP_BINARY, __DIR__.'/projection-child.php', $role, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Cannot start projection contender');
                }
                $processes[$i] = $process;
                $streams[$i] = $pipes;
                stream_set_timeout($pipes[1], 15);
                $pids[$i] = (int) fgets($pipes[1]);
                if ($i === 0) {
                    expect(trim((string) fgets($pipes[1])))->toBe('claimed');
                }
            }
            expect($pids[0])->toBeGreaterThan(0)->and($pids[1])->toBeGreaterThan(0)->and($pids[0])->not->toBe($pids[1]);
            $deadline = microtime(true) + 5;
            do {
                $query = $s->db->owner->prepare('SELECT query, wait_event_type, pg_blocking_pids(pid) @> ARRAY[?]::int[] AS holder FROM pg_stat_activity WHERE pid=?');
                $query->execute([$pids[0], $pids[1]]);
                $row = $query->fetch(\PDO::FETCH_ASSOC);
                $blocked = is_array($row) && $row['wait_event_type'] === 'Lock' && $row['holder'] === true;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            expect($blocked)->toBeTrue()->and($row['query'] ?? null)->toContain('surface_instances', 'for update');
            fwrite(STDERR, 'P4.3 PG barrier '.$mode.' holder='.$pids[0].' waiter='.$pids[1].' waiting on permanent surface row, not absent counter'.PHP_EOL);
            if ($mode === 'rebuild-kill') {
                proc_terminate($processes[0], 9);
            } else {
                fwrite($streams[0][0], "release\n");
                fflush($streams[0][0]);
            }
            foreach ($processes as $i => $process) {
                $output = (string) stream_get_contents($streams[$i][1]);
                $error = (string) stream_get_contents($streams[$i][2]);
                foreach ($streams[$i] as $pipe) {
                    fclose($pipe);
                }
                $exit = proc_close($process);
                unset($processes[$i]);
                if ($mode === 'rebuild-kill' && $i === 0) {
                    expect($exit)->not->toBe(0);

                    continue;
                }
                expect($exit)->toBe(0, $error);
                $wire = Scenario::object(json_decode($output, true, flags: JSON_THROW_ON_ERROR));
                expect($wire['success'])->toBe(! ($mode === 'rebuild-rollback' && $i === 0))->and($wire['level'])->toBe(0);
                foreach (['target_levels', 'authority_levels', 'captures', 'checks'] as $stage) {
                    foreach (Scenario::entries($wire[$stage]) as $level) {
                        expect($level)->toBe($stage === 'checks' ? 1 : 0);
                    }
                }
                expect($wire['checks'])->not->toBe([]);
                fwrite(STDERR, $error);
            }
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
    }
}
