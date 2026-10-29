<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use PDO;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\OperationResult;
use SocKit\Support\Data\ResourceRef;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class ManagementWait
{
    /** The contender has finished outside guards before the observed primary/group lock wait. */
    public static function afterGuards(ContentScenario $s, ResourceRef $post, string $mode, string $change): OperationResult
    {
        $pdo = $s->f->db->owner;
        $pdo->beginTransaction();
        $holder = $pdo->query('SELECT pg_backend_pid()');
        if ($holder === false) {
            throw new \RuntimeException('Missing barrier PID');
        }
        $pid = $holder->fetchColumn();
        $groupOnly = $change === 'leave';
        $lock = $pdo->prepare($groupOnly ? 'SELECT id FROM panel_a.driver_a_groups WHERE id=? FOR UPDATE'
            : 'SELECT id FROM panel_a.driver_a_surface_instances WHERE host_key=? AND kind=? FOR UPDATE');
        $lock->execute($groupOnly ? [$s->group->id] : [$s->group->id, $s->handle->surface->kind->value]);
        expect($lock->fetchColumn())->toBeString();
        $facts = self::facts($s);
        $child = self::start($s, $post, $mode);
        try {
            self::wait($s->f->connection()->getPdo(), $pid, $child['pid'], $groupOnly ? 'a_groups' : 'a_surface_instances');
            // Before the fence there must be no content owner lock, even for a reaction.
            $probe = $pdo->prepare('SELECT id FROM panel_a.driver_a_posts WHERE id=? FOR UPDATE NOWAIT');
            $probe->execute([$post->id]);
            expect($probe->fetchColumn())->toBe($post->id);
            if (in_array($change, ['archive', 'delete'], true)) {
                expect($s->management($change)->isSuccess())->toBeTrue();
            } elseif ($groupOnly) {
                $member = $pdo->prepare("UPDATE panel_a.driver_a_group_members SET state='left', revision=revision+1 WHERE group_id=?");
                $member->execute([$s->group->id]);
                $group = $pdo->prepare('UPDATE panel_a.driver_a_groups SET revision=revision+1, membership_generation=membership_generation+1 WHERE id=?');
                $group->execute([$s->group->id]);
            } else {
                if (! in_array($change, ['revision', 'privacy_generation', 'source_generation'], true)) {
                    throw new \RuntimeException('Invalid generation control');
                }
                $s->f->connection()->table('panel_a.a_surface_instances')->where('host_key', '42')->where('kind', 'acme.runtime')->increment($change);
            }
            if (! $groupOnly) {
                expect(self::facts($s))->toEqual($facts); // host authority/target/group/member unchanged
            }
            fwrite(STDERR, 'P5.4 F1 after_guards change='.$change.' mode='.$mode.' blocked_before_content=true host_group_member_unchanged='.($groupOnly ? 'membership_control' : 'true').PHP_EOL);
            $pdo->commit();

            return self::finish($child);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::stop($child);
        }
    }

    /** @return array{content: OperationResult, management: OperationResult} */
    public static function duringContent(ContentScenario $s, ResourceRef $post, string $mode, string $action): array
    {
        $barrier = new ContentBarrierFence($s->f->fence, $mode === 'post' ? 'posts.create' : 'reactions.add');
        $s->f->app->instance(MutationAuthorityFence::class, $barrier);
        $child = null;
        $barrier->barrier = function () use ($s, $post, $action, &$child): void {
            $holder = $s->f->connection()->scalar('SELECT pg_backend_pid()');
            $child = self::start($s, $post, $action);
            self::wait($s->f->db->owner, $holder, $child['pid'], 'a_surface_instances');
            $row = $s->f->connection()->table('panel_a.a_surface_instances')->where('host_key', '42')->where('kind', 'acme.runtime')->first();
            expect($row?->lifecycle)->toBe('live');
            fwrite(STDERR, 'P5.4 F1 management '.$action.' blocked_by_content_surface_fence=true'.PHP_EOL);
        };
        try {
            if ($mode === 'post') {
                $input = $s->post();
                $content = $s->posts->create($s->handle, $input, $s->command($input, 'management.content.winner.0001'))->operation;
            } else {
                $content = $s->reactions->add($s->handle, $s->reactionInput($post), $s->reaction($post, 'management.reaction.winner.0001'));
            }
            expect($barrier->barrier)->toBeNull();
            if ($child === null) {
                throw new \RuntimeException('Lifecycle contender never launched');
            }

            return ['content' => $content, 'management' => self::finish($child)];
        } finally {
            $s->f->app->instance(MutationAuthorityFence::class, $s->f->fence);
            if ($child !== null) {
                self::stop($child);
            }
        }
    }

    /** @return array{process: resource|null, pipes: array<int, resource>, pid: int} */
    private static function start(ContentScenario $s, ResourceRef $post, string $mode): array
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/content-wait.php', $s->group->id, $mode, $post->id, $s->handle->surface->kind->value],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Cannot launch lifecycle race');
        }
        stream_set_timeout($pipes[1], 15);
        $pid = (int) fgets($pipes[1]);
        expect($pid)->toBeGreaterThan(0);

        return ['process' => $process, 'pipes' => $pipes, 'pid' => $pid];
    }

    private static function wait(PDO $observer, mixed $holder, int $waiter, string $table): void
    {
        if (! is_int($holder)) {
            throw new \RuntimeException('Invalid barrier PID');
        }
        $deadline = microtime(true) + 5;
        do {
            $query = $observer->prepare('SELECT query, wait_event_type, pg_blocking_pids(pid) @> ARRAY[?]::int[] AS blocked FROM pg_stat_activity WHERE pid=?');
            $query->execute([$holder, $waiter]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $blocked = is_array($row) && $row['wait_event_type'] === 'Lock' && $row['blocked'] === true;
            if (! $blocked) {
                usleep(10000);
            }
        } while (! $blocked && microtime(true) < $deadline);
        expect($blocked)->toBeTrue()->and($row['query'] ?? '')->toContain($table, 'for update');
        fwrite(STDERR, 'P5.4 F1 holder='.$holder.' waiter='.$waiter.' actual_lock_wait='.$table.PHP_EOL);
    }

    /** @param array{process: resource|null, pipes: array<int, resource>, pid: int} $child */
    private static function finish(array &$child): OperationResult
    {
        $output = stream_get_contents($child['pipes'][1]);
        $error = stream_get_contents($child['pipes'][2]);
        foreach ($child['pipes'] as $pipe) {
            fclose($pipe);
        }
        $child['pipes'] = [];
        if (! is_resource($child['process'])) {
            throw new \RuntimeException('Missing lifecycle contender');
        }
        $exit = proc_close($child['process']);
        $child['process'] = null;
        expect($exit)->toBe(0, $error === false ? 'Missing stderr' : $error);
        $wire = Scenario::object(json_decode($output === false ? '' : $output, true, flags: JSON_THROW_ON_ERROR));
        expect($wire['level'] ?? null)->toBe(0)->and($wire['resolves'] ?? null)->toBeIn([[], [0]])
            ->and($wire['elapsed'] ?? null)->toBeLessThan(5.0);
        if ($error !== false) {
            fwrite(STDERR, $error);
        }

        return OperationResult::fromWire(Scenario::object($wire['result'] ?? null));
    }

    /** @param array{process: resource|null, pipes: array<int, resource>, pid: int} $child */
    private static function stop(array $child): void
    {
        foreach ($child['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($child['process'])) {
            proc_terminate($child['process']);
            proc_close($child['process']);
        }
    }

    /** @return list<array<object>> */
    private static function facts(ContentScenario $s): array
    {
        $result = [];
        foreach (['a_groups', 'a_group_members', 'envelope_targets', 'envelope_authority'] as $table) {
            $result[] = $s->f->connection()->table('panel_a.'.$table)->get()->all();
        }

        return $result;
    }
}
