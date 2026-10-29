<?php

declare(strict_types=1);

namespace Acme\Groups;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\OperationResult;

final class Race
{
    /** Prove a committed leave wins while a real installed-package operation waits.
     * @param  \Closure(): OperationResult  $leave
     */
    public static function leaveFirst(Application $app, string $group, string $post, string $mode, \Closure $leave): void
    {
        $db = $app->make(DatabaseManager::class);
        $connection = $db->connection('owner_a');
        $observer = $db->connection('owner_b');
        $original = $app->make(MutationAuthorityFence::class);
        $fence = new BarrierFence($original, 'groups.leave');
        $app->instance(MutationAuthorityFence::class, $fence);
        $process = null;
        $pipes = [];
        $fence->barrier = static function () use ($connection, $observer, $group, $post, $mode, &$process, &$pipes): void {
            $holder = $connection->scalar('SELECT pg_backend_pid()');
            if (! is_int($holder)) {
                throw new \RuntimeException('Invalid holder PID');
            }
            $process = proc_open([PHP_BINARY, __DIR__.'/race.php', $group, $post, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new \RuntimeException('Failed to start installed race worker');
            }
            stream_set_timeout($pipes[1], 15);
            $pid = (int) fgets($pipes[1]);
            $deadline = microtime(true) + 8;
            do {
                $row = $observer->selectOne('SELECT query, wait_event_type, pg_blocking_pids(?) @> ARRAY[?]::int[] AS blocked FROM pg_stat_activity WHERE pid=?', [$pid, $holder, $pid]);
                $blocked = $row instanceof \stdClass && $row->wait_event_type === 'Lock' && $row->blocked === true;
                if (! $blocked) {
                    usleep(10000);
                }
            } while (! $blocked && microtime(true) < $deadline);
            if (! $blocked || ! is_string($row->query) || ! str_contains(strtolower($row->query), 'for update')) {
                throw new \RuntimeException('Contender did not wait on owner lock');
            }
            $observer->beginTransaction();
            try {
                $row = $observer->selectOne('SELECT id FROM public.driver_article_posts WHERE id=? FOR UPDATE NOWAIT', [$post]);
                if (! $row instanceof \stdClass || $row->id !== $post) {
                    throw new \RuntimeException('Post lock unexpectedly unavailable');
                }
            } finally {
                $observer->rollBack();
            }
            fwrite(STDERR, 'P5.7 installed leave-first '.$mode.' holder='.$holder.' waiter='.$pid.' waited=true post_unlocked=true'.PHP_EOL);
        };
        try {
            $winner = $leave();
            if (! $winner->isSuccess() || ! is_resource($process)) {
                throw new \RuntimeException('Leave did not reach installed barrier');
            }
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $exit = proc_close($process);
            $process = null;
            $wire = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
            $result = is_array($wire) ? ($wire['result'] ?? null) : null;
            if (! is_array($result)) {
                throw new \RuntimeException('Invalid contender result');
            }
            $resultObject = [];
            foreach ($result as $key => $value) {
                if (! is_string($key)) {
                    throw new \RuntimeException('Invalid contender result key');
                }
                $resultObject[$key] = $value;
            }
            if ($exit !== 0 || ($wire['level'] ?? null) !== 0 || OperationResult::fromWire($resultObject)->isSuccess()) {
                throw new \RuntimeException('Stale contender succeeded: '.$output.' '.$error);
            }
        } finally {
            $app->instance(MutationAuthorityFence::class, $original);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
