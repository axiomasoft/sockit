<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Operations\Data\OperationResult;
use SocKit\Support\Data\ResourceRef;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class ConsentWait
{
    public static function revoke(MembershipScenario $s, ResourceRef $group, bool $revoke): OperationResult
    {
        $owner = $s->f->db->owner;
        $owner->beginTransaction();
        $holder = PgTarget::query($owner, 'SELECT pg_backend_pid()')->fetchColumn();
        $lock = $owner->prepare('SELECT id FROM panel_a.driver_a_groups WHERE id=? FOR UPDATE');
        $lock->execute([$group->id]);
        $process = proc_open([PHP_BINARY, __DIR__.'/consent-wait.php', $group->id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            $owner->rollBack();
            throw new \RuntimeException('Cannot start consent contender');
        }
        try {
            stream_set_timeout($pipes[1], 15);
            $waiter = (int) fgets($pipes[1]);
            expect($waiter)->toBeGreaterThan(0);
            $deadline = microtime(true) + 5;
            do {
                $query = $s->f->connection()->getPdo()->prepare('SELECT query, wait_event_type, pg_blocking_pids(pid) @> ARRAY[?]::int[] AS blocked FROM pg_stat_activity WHERE pid=?');
                $query->execute([$holder, $waiter]);
                $row = $query->fetch(\PDO::FETCH_ASSOC);
                $blocked = is_array($row) && $row['wait_event_type'] === 'Lock' && $row['blocked'] === true;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            expect($blocked)->toBeTrue()->and($row['query'] ?? '')->toContain('a_groups', 'for update');
            fwrite(STDERR, 'P5.3 consent barrier holder='.$holder.' waiter='.$waiter.' actual_group_lock_wait=true'.PHP_EOL);
            $owner->exec('UPDATE panel_a.driver_membership_consent SET generation=generation+1'.($revoke ? ',allowed=false' : ''));
            $owner->commit();
            $output = (string) stream_get_contents($pipes[1]);
            $error = (string) stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $exit = proc_close($process);
            $process = null;
            expect($exit)->toBe(0, $error);
            $wire = Scenario::object(json_decode($output, true, flags: JSON_THROW_ON_ERROR));
            expect($wire['assess'] ?? null)->toBe([0, 0])->and($wire['recheck'] ?? null)->toBe([1])->and($wire['level'] ?? null)->toBe(0);
            fwrite(STDERR, $error);

            return OperationResult::fromWire(Scenario::object($wire['result'] ?? null));
        } finally {
            if ($owner->inTransaction()) {
                $owner->rollBack();
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
