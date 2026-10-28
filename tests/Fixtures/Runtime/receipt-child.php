<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Internal\Receipts\ReceiptException;
use SocKit\Operations\Internal\Receipts\ReceiptLedger;
use SocKit\Tests\Fixtures\Runtime\ReceiptScenario as Receipts;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;

$app = Runtime::app();
$fake = Runtime::trust($app);
$db = new RuntimeDatabase($app);
$connection = $db->capsule->getConnection('owner_a');
$connection->statement("SET statement_timeout = '10s'");
$pid = $connection->scalar('SELECT pg_backend_pid()');
if (! is_int($pid)) {
    throw new RuntimeException('Invalid child PID');
}
fwrite(STDOUT, $pid.PHP_EOL);
fflush(STDOUT);
$owner = Receipts::owner($app);
$role = $argv[1];
$mode = $argv[2];
$payload = ['value' => $role === 'wait' && $mode === 'conflict' ? 2 : 1];
if ($role === 'wait' && $mode === 'actor') {
    $fake->actors->actingAs(Runtime::actor('8'));
    $owner = Receipts::owner($app);
}
$envelope = Receipts::envelope(payload: $payload);
$writes = 0;
$usableInside = false;
$connection->listen(function (QueryExecuted $query) use ($connection, &$usableInside): void {
    if (str_contains($query->sql, 'ON CONFLICT (namespace, command_key)')) {
        $usableInside = $connection->transactionLevel() === 1 && $connection->scalar('SELECT 42') === 42;
    }
});
try {
    $snapshot = new ReceiptLedger($owner)->transaction(envelope: $envelope, commandType: Receipts::TYPE, payload: $payload, write: function (string $id) use ($owner, $role, $mode, &$writes): OperationResult {
        $writes++;
        $result = Receipts::effect(owner: $owner, id: $id);
        if ($role === 'hold') {
            fwrite(STDOUT, "claimed\n");
            fflush(STDOUT);
            stream_set_timeout(STDIN, 10);
            if (trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Missing receipt barrier release');
            }
            if ($mode === 'rollback') {
                throw new RuntimeException('winner rollback');
            }
        }

        return $result;
    });
    $result = ['outcome' => $snapshot->outcome->value, 'receipt' => $snapshot->id, 'resource' => $snapshot->resource?->toWire(), 'revision' => $snapshot->revision?->value, 'writes' => $writes];
} catch (ReceiptException $error) {
    $result = ['problem' => $error->problem()->value, 'writes' => $writes];
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'winner rollback') {
        throw $error;
    }
    $result = ['rollback' => true, 'writes' => $writes];
}
// Prove PostgreSQL remains usable in the SAME transaction after ON CONFLICT, via query listener.
// The enclosing transaction is also closed cleanly after commit/rejection/rollback.
$result['usable_inside'] = $usableInside;
if ($role === 'lost') {
    $connection->disconnect();
    fwrite(STDERR, "P2.5 lost response: committed; closed PDO before emitting snapshot\n");
    exit(0);
}
$result['usable'] = $connection->scalar('SELECT 42') === 42;
$result['transaction_level'] = $connection->transactionLevel();
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
