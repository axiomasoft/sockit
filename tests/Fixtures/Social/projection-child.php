<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Social\ReactionScenario;

$s = new ReactionScenario(install: false);
$role = $argv[1];
$mode = $argv[2];
$connection = $s->connection();
$connection->statement("SET statement_timeout='10s'");
$pid = $connection->scalar('SELECT pg_backend_pid()');
if (! is_int($pid)) {
    throw new RuntimeException('Invalid projection backend PID');
}
fwrite(STDOUT, $pid.PHP_EOL);
fflush(STDOUT);
$rebuild = ($role === 'hold') === str_starts_with($mode, 'rebuild-');
$held = false;
if ($role === 'hold') {
    $connection->listen(function (QueryExecuted $query) use (&$held, $rebuild, $mode): void {
        $matches = $rebuild
            ? str_starts_with($query->sql, 'delete') && str_contains($query->sql, '"driver_a_reaction_counts"')
            : (str_starts_with($query->sql, 'insert') || str_starts_with($query->sql, 'update')) && str_contains($query->sql, '"driver_a_reactions"');
        if (! $held && $matches) {
            $held = true;
            fwrite(STDOUT, "claimed\n");
            fflush(STDOUT);
            stream_set_timeout(STDIN, 10);
            if (trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Projection barrier missing');
            }
            if ($mode === 'rebuild-rollback') {
                throw new RuntimeException('Interrupted rebuild');
            }
        }
    });
}
try {
    if ($rebuild) {
        $s->reactions->rebuild(handle: $s->handle, target: $s->reaction()->target);
        $success = true;
    } else {
        $action = str_ends_with($mode, 'remove') ? 'remove' : 'add';
        $result = $s->execute(command: $s->command(action: $action, expected: $action === 'remove' ? new Revision(1) : null, key: 'projection.race.0001'), action: $action);
        $success = $result->isSuccess();
    }
} catch (RuntimeException $error) {
    if ($mode !== 'rebuild-rollback' || $role !== 'hold' || $error->getMessage() !== 'Interrupted rebuild') {
        throw $error;
    }
    $success = false;
}
fwrite(STDOUT, json_encode(['success' => $success, 'level' => $connection->transactionLevel(), 'target_levels' => $s->target->levels,
    'authority_levels' => $s->authority->levels, 'captures' => $s->fence->captures, 'checks' => $s->fence->checks], JSON_THROW_ON_ERROR).PHP_EOL);
