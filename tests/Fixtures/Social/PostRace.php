<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Operations\Data\OperationResult;
use SocKit\Support\Data\ResourceRef;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class PostRace
{
    /** @return list<OperationResult> */
    public static function run(PostScenario $s, string $mode, ?ResourceRef $resource = null): array
    {
        $processes = $streams = $pids = [];
        try {
            foreach (['hold', 'wait'] as $i => $role) {
                $process = proc_open([PHP_BINARY, __DIR__.'/post-child.php', $role, $mode, $resource->id ?? ''], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Cannot start post contender');
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
            expect($blocked)->toBeTrue();
            if ($mode !== 'edit') {
                expect($row['query'] ?? null)->toContain('INSERT INTO', 'ON CONFLICT');
            }
            fwrite(STDERR, 'P4.4 real PG barrier '.$mode.' holder='.$pids[0].' waiter='.$pids[1].' blocked=true'.PHP_EOL);
            fwrite($streams[0][0], "release\n");
            fflush($streams[0][0]);
            $results = [];
            foreach ($processes as $i => $process) {
                $output = (string) stream_get_contents($streams[$i][1]);
                $error = (string) stream_get_contents($streams[$i][2]);
                foreach ($streams[$i] as $pipe) {
                    fclose($pipe);
                }
                expect(proc_close($process))->toBe(0, $error);
                unset($processes[$i]);
                $wire = Scenario::object(json_decode($output, true, flags: JSON_THROW_ON_ERROR));
                expect($wire['level'])->toBe(0);
                foreach (['target_levels', 'authority_levels', 'captures', 'checks', 'safety_levels', 'media_levels'] as $stage) {
                    foreach (Scenario::entries($wire[$stage]) as $level) {
                        expect($level)->toBe($stage === 'checks' ? 1 : 0);
                    }
                }
                $results[] = OperationResult::fromWire(Scenario::object($wire['result']));
                fwrite(STDERR, $error);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
