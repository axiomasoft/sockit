<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Operations\Data\OperationResult;
use SocKit\Support\Data\ResourceRef;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class MembershipRace
{
    /** @return list<OperationResult> */
    public static function run(MembershipScenario $s, ResourceRef $group, string $mode, string $first = 'join', string $second = 'join', string $firstActor = '7', string $secondActor = '7', string $firstSubject = '-', string $secondSubject = '-'): array
    {
        $processes = $pipes = $pids = [];
        try {
            foreach (['hold', 'wait'] as $i => $role) {
                $process = proc_open([PHP_BINARY, __DIR__.'/race-child.php', $group->id, $role, $mode, $i === 0 ? $first : $second, $i === 0 ? $firstActor : $secondActor, $i === 0 ? $firstSubject : $secondSubject, (string) $s->revision($group)->value], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $streams);
                if (! is_resource($process)) {
                    throw new \RuntimeException('Cannot start contender');
                }
                $processes[$i] = $process;
                $pipes[$i] = $streams;
                stream_set_timeout($streams[1], 15);
                $pids[$i] = (int) fgets($streams[1]);
                expect($pids[$i])->toBeGreaterThan(0);
                if ($i === 0) {
                    expect(trim((string) fgets($streams[1])))->toBe('held');
                }
            }
            expect($pids[0])->not->toBe($pids[1]);
            $deadline = microtime(true) + 5;
            do {
                $q = $s->f->db->owner->prepare('SELECT query, wait_event_type, pg_blocking_pids(pid) @> ARRAY[?]::int[] AS blocked FROM pg_stat_activity WHERE pid=?');
                $q->execute([$pids[0], $pids[1]]);
                $row = $q->fetch(\PDO::FETCH_ASSOC);
                $blocked = is_array($row) && $row['wait_event_type'] === 'Lock' && $row['blocked'] === true;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            expect($blocked)->toBeTrue();
            $sql = is_array($row) && is_string($row['query'] ?? null) ? $row['query'] : '';
            fwrite(STDERR, 'P5.5 barrier mode='.$mode.' holder='.$pids[0].' waiter='.$pids[1].' query='.$sql.PHP_EOL);
            if ($mode === 'consent_rollback') {
                expect($sql)->toContain('a_receipts', 'ON CONFLICT');
                $s->f->db->owner->exec('UPDATE panel_a.driver_membership_consent SET allowed=false,generation=generation+1');
            }
            fwrite($pipes[0][0], "release\n");
            fflush($pipes[0][0]);
            $results = [];
            foreach ($processes as $i => $process) {
                $out = (string) stream_get_contents($pipes[$i][1]);
                $err = (string) stream_get_contents($pipes[$i][2]);
                foreach ($pipes[$i] as $pipe) {
                    fclose($pipe);
                }
                expect(proc_close($process))->toBe(0, $err);
                unset($processes[$i]);
                $wire = Scenario::object(json_decode($out, true, flags: JSON_THROW_ON_ERROR));
                expect($wire['level'])->toBe(0)->and($wire['held'])->toBe($i === 0);
                $results[] = OperationResult::fromWire(Scenario::object($wire['result']));
                fwrite(STDERR, $err);
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
