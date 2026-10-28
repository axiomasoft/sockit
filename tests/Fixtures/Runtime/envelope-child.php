<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Internal\Receipts\ReceiptLedger;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\SurfaceInstances;
use SocKit\Tests\Fixtures\Runtime\EnvelopeAuthority;
use SocKit\Tests\Fixtures\Runtime\EnvelopeScenario;

$s = new EnvelopeScenario(install: false);
$connection = $s->base->db->capsule->getConnection('owner_a');
$connection->statement("SET statement_timeout='10s'");
$authority = new EnvelopeAuthority($connection);
$s->base->app->instance(abstract: ActionAuthorizer::class, instance: $authority);
$pid = $connection->scalar('SELECT pg_backend_pid()');
if (! is_int($pid)) {
    throw new RuntimeException('Invalid contender backend PID');
}
fwrite(STDOUT, $pid.PHP_EOL);
fflush(STDOUT);
$role = $argv[1];
$mode = $argv[2];
$held = false;
$rollbackClaim = in_array($mode, ['revoke_rollback', 'lifecycle_revoke_rollback', 'generation_rollback'], true);
$barrier = static function (): void {
    fwrite(STDOUT, "claimed\n");
    fflush(STDOUT);
    stream_set_timeout(STDIN, 10);
    if (trim((string) fgets(STDIN)) !== 'release') {
        throw new RuntimeException('Missing envelope barrier');
    }
};
if ($role === 'hold' && ! $rollbackClaim && $mode !== 'revoke') {
    $connection->listen(function (QueryExecuted $query) use (&$held, $mode, $barrier): void {
        if (! $held && str_starts_with($query->sql, 'update') && (str_contains($query->sql, 'envelope_state') || (in_array($mode, ['archive', 'delete'], true) && str_contains($query->sql, 'a_surface_instances')))) {
            $held = true;
            $barrier();
            if ($mode === 'rollback') {
                throw new RuntimeException('injected winner rollback');
            }
        }
    });
}
if ($role === 'wait' && $mode === 'digest') {
    $s->mutation->value = 2;
}
$lifecycle = $s->base->app->make(SurfaceInstances::class);
$isLifecycle = ($role === 'hold' && in_array($mode, ['archive', 'delete'], true)) || $mode === 'lifecycle_revoke_rollback';
if ($isLifecycle) {
    $action = $mode === 'delete' ? 'delete' : 'archive';
    $command = new CommandEnvelope(key: new CommandKey('req.2026-10-28.lifecycle'), namespace: new CommandNamespace(installation: $s->handle->actor->installation, panel: $s->handle->surface->panel, integration: 'acme.client', commandType: 'sockit.surfaces.'.$action),
        digest: $lifecycle->digest(handle: $s->handle, action: $action, expectedRevision: new Revision(1)), expectedRevision: new Revision(1));
} else {
    $command = $s->command(key: $mode === 'cas' && $role === 'wait' ? 'req.2026-10-28.cas2' : 'req.2026-10-28.0001');
}
if ($role === 'hold' && $rollbackClaim) {
    // A real uncommitted claim, deliberately rolled back BEFORE any owner/fence/state effect.
    // Payload follows the documented canonical binding; digest is validated by ReceiptLedger.
    $request = $s->mutation->request($s->handle);
    $payload = ['surface' => $s->handle->surface->toWire(), 'target' => $isLifecycle ? null : $request->target?->toWire(), 'audience' => null,
        'expected_revision' => 1, 'subject_audience' => AudienceSpec::direct($s->handle->actor->actor ?? throw new RuntimeException('actor required'))->toWire(),
        'action' => $isLifecycle ? 'sockit.surfaces.archive' : $request->action, 'intent' => 'write', 'data' => $isLifecycle ? [] : $s->mutation->payload()];
    $owner = $s->base->app->make(OwnerStorage::class)->forHandle(handle: $s->handle, namespace: $command->namespace);
    try {
        new ReceiptLedger($owner)->transaction(envelope: $command, commandType: $command->namespace->commandType, payload: $payload, guardContext: '{}', write: static function (string $id) use ($barrier): OperationResult {
            $barrier();
            throw new RuntimeException('injected claim rollback');
        });
        throw new RuntimeException('Claim holder unexpectedly committed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'injected claim rollback') {
            throw $error;
        }
        $result = OperationResult::rejected(ProblemCode::ProviderUnavailable);
    }
} elseif ($role === 'hold' && $mode === 'revoke') {
    // Parent already committed the original effect. Hold ONLY its receipt read lock;
    // current authority can revoke while another replay waits, without a mutation fence.
    $owner = $s->base->app->make(OwnerStorage::class)->forHandle(handle: $s->handle, namespace: $command->namespace);
    $ledger = new ReceiptLedger($owner);
    $context = $ledger->context(namespace: $command->namespace, key: $command->key) ?? throw new RuntimeException('Missing committed winner');
    $connection->listen(function (QueryExecuted $query) use (&$held, $barrier): void {
        if (! $held && str_contains($query->sql, 'a_receipts') && str_contains($query->sql, 'for update')) {
            $held = true;
            $barrier();
        }
    });
    $ledger->result(envelope: $command, guardContext: $context);
    $result = $s->execute($command);
} elseif ($isLifecycle) {
    $result = $lifecycle->{$action}(handle: $s->handle, command: $command);
} else {
    $result = $s->execute($command);
}
if ($role === 'lost') {
    $connection->disconnect();
    fwrite(STDERR, "P2.7 lost response after REAL commit; no outcome returned\n");
    exit(0);
}
fwrite(STDOUT, json_encode(['result' => $result->toWire(), 'writes' => $s->mutation->writes, 'resolvers' => $s->target->levels, 'authority' => $authority->levels,
    'fence_captures' => $s->fence->captures, 'fence_checks' => $s->fence->checks, 'level' => $connection->transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL);
