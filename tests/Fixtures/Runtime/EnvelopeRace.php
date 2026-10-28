<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Operations\Data\OperationResult;

final class EnvelopeRace
{
    /** @return list<OperationResult> */
    public static function run(EnvelopeScenario $s, string $mode): array
    {
        $processes = $streams = $pids = [];
        try {
            if ($mode === 'revoke') {
                expect($s->execute()->isSuccess())->toBeTrue();
            }
            foreach (['hold', 'wait'] as $i => $role) {
                $process = proc_open([PHP_BINARY, __DIR__.'/envelope-child.php', $role, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Could not start contender');
                }
                $processes[$i] = $process;
                $streams[$i] = $pipes;
                stream_set_timeout($pipes[1], 10);
                $pids[$i] = (int) fgets($pipes[1]);
                if ($i === 0) {
                    expect(trim((string) fgets($pipes[1])))->toBe('claimed');
                }
            }
            expect($pids[0])->toBeGreaterThan(0)->and($pids[1])->toBeGreaterThan(0)->and($pids[0])->not->toBe($pids[1]);
            $deadline = microtime(true) + 5;
            do {
                $query = $s->base->db->owner->prepare('SELECT query, wait_event_type, pg_blocking_pids(pid) @> ARRAY[?]::int[] AS holder FROM pg_stat_activity WHERE pid=?');
                $query->execute([$pids[0], $pids[1]]);
                $row = $query->fetch(\PDO::FETCH_ASSOC);
                $blocked = is_array($row) && $row['wait_event_type'] === 'Lock' && $row['holder'] === true;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            fwrite(STDERR, 'P2.7 barrier '.$mode.' holder='.$pids[0].' waiter='.$pids[1].' blocked_by_holder='.($blocked ? 'true' : 'FALSE').PHP_EOL);
            expect($blocked)->toBeTrue();
            if (in_array($mode, ['revoke_rollback', 'lifecycle_revoke_rollback', 'generation_rollback'], true)) {
                expect($row['query'] ?? null)->toBeString()->toContain('INSERT INTO', 'ON CONFLICT');
                fwrite(STDERR, 'P2.7 UNIQUE wait verified before holder rollback'.PHP_EOL);
            }
            if (in_array($mode, ['revoke', 'revoke_rollback', 'lifecycle_revoke_rollback'], true)) {
                $s->base->db->owner->exec('UPDATE panel_a.driver_envelope_authority SET allowed=false,generation=generation+1');
            }
            if ($mode === 'generation_rollback') {
                $s->base->db->owner->exec('UPDATE panel_a.driver_envelope_authority SET generation=generation+1');
            }
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
                $wire = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($wire)) {
                    throw new \RuntimeException('Invalid contender result');
                }
                $result = [];
                foreach ($wire as $key => $value) {
                    if (! is_string($key)) {
                        throw new \RuntimeException('Invalid contender key');
                    } $result[$key] = $value;
                }
                foreach (['resolvers', 'authority'] as $stage) {
                    $levels = $result[$stage] ?? null;
                    if (! is_array($levels)) {
                        throw new \RuntimeException('Missing resolver levels');
                    }
                    if ($i === 0 && in_array($mode, ['revoke_rollback', 'lifecycle_revoke_rollback', 'generation_rollback'], true)) {
                        expect($levels)->toBe([]);

                        continue;
                    }
                    if ($stage === 'authority' || (! in_array($mode, ['archive', 'delete', 'lifecycle_revoke_rollback'], true) || ($i === 1 && $mode !== 'lifecycle_revoke_rollback'))) {
                        foreach ($levels as $level) {
                            expect($level)->toBe(0);
                        }
                        expect($levels)->not->toBe([]);
                    } else {
                        expect($levels)->toBe([]);
                    }
                }
                foreach (['fence_captures' => 0, 'fence_checks' => 1] as $stage => $expectedLevel) {
                    if (! is_array($result[$stage] ?? null)) {
                        throw new \RuntimeException('Missing fence instrumentation');
                    }
                    foreach ($result[$stage] as $level) {
                        expect($level)->toBe($expectedLevel);
                    }
                }
                if ($i === 1 && in_array($mode, ['revoke_rollback', 'lifecycle_revoke_rollback', 'generation_rollback'], true)) {
                    expect($result['fence_captures'])->toBe([0])->and($result['fence_checks'])->toBe([1]);
                }
                expect($result['level'])->toBe(0);
                $outcome = $result['result'] ?? null;
                if (! is_array($outcome)) {
                    throw new \RuntimeException('Missing contender outcome');
                }
                $validated = [];
                foreach ($outcome as $key => $value) {
                    if (! is_string($key)) {
                        throw new \RuntimeException('Invalid outcome key');
                    } $validated[$key] = $value;
                }
                $results[] = OperationResult::fromWire($validated);
                fwrite(STDERR, $error);
            }
            fwrite(STDERR, 'P2.7 race outcomes '.json_encode($results, JSON_THROW_ON_ERROR).PHP_EOL);

            return $results;
        } finally {
            foreach ($processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
