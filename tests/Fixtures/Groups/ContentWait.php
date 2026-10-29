<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Support\Data\ResourceRef;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class ContentWait
{
    /** @return array{winner: OperationResult, contender: OperationResult} */
    public static function race(ContentScenario $s, ResourceRef $post, string $mode, bool $leaveFirst): array
    {
        $connection = $s->f->connection();
        $barrier = new ContentBarrierFence($s->f->fence, $leaveFirst ? 'groups.leave' : ($mode === 'post' ? 'posts.create' : 'reactions.add'));
        $s->f->app->instance(MutationAuthorityFence::class, $barrier);
        $process = null;
        $pipes = [];
        $barrier->barrier = function () use ($s, $post, $mode, $leaveFirst, $connection, &$process, &$pipes): void {
            $holder = $connection->scalar('SELECT pg_backend_pid()');
            if (! is_int($holder)) {
                throw new \RuntimeException('Invalid holder PID');
            }
            $process = proc_open([PHP_BINARY, __DIR__.'/content-wait.php', $s->group->id, $leaveFirst ? $mode : 'leave', $post->id],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new \RuntimeException('Cannot start group content contender');
            }
            stream_set_timeout($pipes[1], 15);
            $waiter = (int) fgets($pipes[1]);
            expect($waiter)->toBeGreaterThan(0);
            $deadline = microtime(true) + 5;
            do {
                $query = $s->f->db->owner->prepare('SELECT query, wait_event_type, pg_blocking_pids(pid) @> ARRAY[?]::int[] AS blocked FROM pg_stat_activity WHERE pid=?');
                $query->execute([$holder, $waiter]);
                $row = $query->fetch(\PDO::FETCH_ASSOC);
                $blocked = is_array($row) && $row['wait_event_type'] === 'Lock' && $row['blocked'] === true;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            expect($blocked)->toBeTrue()->and($row['query'] ?? '')->toContain('a_surface_instances', 'for update');
            if ($leaveFirst) {
                // The waiting content operation must not yet hold its post owner row.
                $s->f->db->owner->beginTransaction();
                try {
                    $lock = $s->f->db->owner->prepare('SELECT id FROM panel_a.driver_a_posts WHERE id=? FOR UPDATE NOWAIT');
                    $lock->execute([$post->id]);
                    expect($lock->fetchColumn())->toBe($post->id);
                } finally {
                    $s->f->db->owner->rollBack();
                }
            }
            fwrite(STDERR, 'P5.4 holder='.$holder.' waiter='.$waiter.' management_surface_lock_wait=true post_lock_not_taken='.($leaveFirst ? 'true' : 'winner_holds_ordered_fence').
                ' mode='.$mode.' leave_first='.($leaveFirst ? 'true' : 'false').PHP_EOL);
        };
        try {
            if ($leaveFirst) {
                $winner = $s->membership->run($s->group, 'leave');
            } elseif ($mode === 'post') {
                $input = $s->post();
                $winner = $s->posts->create($s->handle, $input, $s->command($input, 'group.winning.post.0001'))->operation;
            } else {
                $winner = $s->reactions->add($s->handle, $s->reactionInput($post), $s->reaction($post, 'group.winning.reaction.0001'));
            }
            expect($barrier->barrier)->toBeNull();
            if ($leaveFirst) {
                expect($winner->isSuccess())->toBeTrue();
            } else {
                // A revocation committed after the content commit may deny the final current
                // output guard. Durable owner effect below proves which operation won the lock.
                expect($winner->outcome)->toBeIn([OperationOutcome::Confirmed, OperationOutcome::Rejected]);
            }
            if (! is_resource($process)) {
                throw new \RuntimeException('Contender was not launched');
            }
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $exit = proc_close($process);
            $process = null;
            expect($exit)->toBe(0, $error === false ? 'unreadable stderr' : $error);
            $wire = Scenario::object(json_decode($output === false ? '' : $output, true, flags: JSON_THROW_ON_ERROR));
            expect($wire['level'] ?? null)->toBe(0)->and($wire['resolves'] ?? null)->toBe([0]);
            if ($error !== false) {
                fwrite(STDERR, $error);
            }

            return ['winner' => $winner, 'contender' => OperationResult::fromWire(Scenario::object($wire['result'] ?? null))];
        } finally {
            $s->f->app->instance(MutationAuthorityFence::class, $s->f->fence);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
