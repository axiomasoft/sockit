<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Internal\Receipts\ReceiptLedger;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Social\ReactionScenario;

$s = new ReactionScenario(install: false);
$role = $argv[1];
$mode = $argv[2];
$connection = $s->connection();
$connection->statement("SET statement_timeout='10s'");
$pid = $connection->scalar('SELECT pg_backend_pid()');
if (! is_int($pid)) {
    throw new RuntimeException('Invalid backend PID');
}
fwrite(STDOUT, $pid.PHP_EOL);
fflush(STDOUT);
$reaction = $s->reaction(kind: $role === 'wait' && $mode === 'digest' ? 'thanks' : 'like');
$action = $role === 'wait' && $mode === 'add-remove' ? 'remove' : 'add';
$command = $s->command(action: $action, expected: $mode === 'add-remove' ? new Revision(2) : null,
    key: $role === 'wait' && in_array($mode, ['different', 'add-remove'], true) ? 'reaction.request.0002' : 'reaction.request.0001', reaction: $reaction);
$barrier = static function (): void {
    fwrite(STDOUT, "claimed\n");
    fflush(STDOUT);
    stream_set_timeout(STDIN, 10);
    if (trim((string) fgets(STDIN)) !== 'release') {
        throw new RuntimeException('Missing reaction barrier');
    }
};
if ($role === 'hold' && in_array($mode, ['revoke-rollback', 'generation-rollback'], true)) {
    $owner = $s->app->make(OwnerStorage::class)->forHandle(handle: $s->handle, namespace: $command->namespace);
    $payload = ['surface' => $s->handle->surface->toWire(), 'target' => $reaction->target->toWire(), 'audience' => null, 'expected_revision' => null,
        'subject_audience' => AudienceSpec::direct($s->handle->actor->actor ?? throw new RuntimeException('actor missing'))->toWire(),
        'action' => 'reactions.add', 'intent' => 'write', 'data' => ['kind' => 'like']];
    try {
        new ReceiptLedger($owner)->transaction(envelope: $command, commandType: 'reactions.add', payload: $payload, guardContext: '{}',
            write: static function (string $id) use ($barrier): OperationResult {
                $barrier();
                throw new RuntimeException('injected claim rollback');
            });
        throw new RuntimeException('Claim unexpectedly committed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'injected claim rollback') {
            throw $error;
        }
        $result = OperationResult::rejected(ProblemCode::ProviderUnavailable);
    }
} else {
    $held = false;
    if ($role === 'hold') {
        $connection->listen(function (QueryExecuted $query) use (&$held, $barrier, $mode): void {
            if (! $held && (str_starts_with($query->sql, 'insert') || str_starts_with($query->sql, 'update')) && str_contains($query->sql, '"driver_a_reactions"')) {
                $held = true;
                $barrier();
                if ($mode === 'rollback') {
                    throw new RuntimeException('injected membership rollback');
                }
            }
        });
    }
    $result = $s->execute(command: $command, reaction: $reaction, action: $action);
}
if ($role === 'lost') {
    if (! $result->isSuccess()) {
        throw new RuntimeException('Lost response operation failed');
    }
    fwrite(STDERR, "P4.2 lost response AFTER real commit, original key retained\n");
    exit(0);
}
fwrite(STDOUT, json_encode(['result' => $result->toWire(), 'target_levels' => $s->target->levels, 'authority_levels' => $s->authority->levels,
    'captures' => $s->fence->captures, 'checks' => $s->fence->checks, 'level' => $connection->transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL);
