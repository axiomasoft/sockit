<?php

declare(strict_types=1);

use Acme\Runtime\EnvelopeMutation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Operations\OutboxRelay;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\SurfaceInstances;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid application');
}
$app->make(Kernel::class)->bootstrap();
$conn = $app->make(DatabaseManager::class)->connection('owner_a');
$ref = new SurfaceRef(panel: new PanelId('acme.article'), kind: new SurfaceKindId('acme.runtime'), host: new HostEntityRef(type: 'article', value: '42'), incarnation: new Incarnation(1));
$handle = $app->make(OperationHandles::class)->forSurface($ref);
if (($argv[1] ?? '') === 'quarantine') {
    $results = $app->make(OutboxRelay::class)->poll($handle);
    if (count($results) !== 2 || array_filter($results, static fn ($result): bool => $result->status !== 'quarantine' || $result->reason !== 'outbox.consumer_unavailable') !== []) {
        throw new RuntimeException('Missing quarantine');
    }
    echo json_encode(['quarantine' => $results[0]->reason], JSON_THROW_ON_ERROR);
    exit;
}
$api = $app->make(SurfaceInstances::class);
$operations = $app->make(Operations::class);
$ns = static fn (string $type): CommandNamespace => new CommandNamespace(installation: $handle->actor->installation, panel: $ref->panel, integration: 'acme.client', commandType: $type);
$create = new CommandEnvelope(key: new CommandKey('consumer.lifecycle.create'), namespace: $ns('sockit.surfaces.create'), digest: $api->digest(handle: $handle, action: 'create'));
$created = $api->create(handle: $handle, command: $create);
if (! $created->isSuccess() || $created->resource === null || $api->get($handle)->revision->value !== 1) {
    throw new RuntimeException('Surface creation failed');
}
$conn->table('public.envelope_revisions')->insert(['target' => '42', 'resource' => $created->resource->id, 'revision' => 1]);
$mutation = new EnvelopeMutation($conn, 'public');
$command = static fn (string $key, Revision $expected): CommandEnvelope => new CommandEnvelope(key: new CommandKey($key), namespace: $ns(EnvelopeMutation::TYPE), digest: $operations->digest(handle: $handle, mutation: $mutation, expectedRevision: $expected), expectedRevision: $expected);
$original = $command('consumer.original.1', new Revision(1));
$first = $operations->execute(handle: $handle, command: $original, mutation: $mutation);
if (! $first->isSuccess() || $operations->execute(handle: $handle, command: $original, mutation: $mutation)->toWire() !== $first->toWire()) {
    throw new RuntimeException('Mutation or replay failed');
}
$before = [$conn->table('public.envelope_state')->value('effects'), $conn->table('public.envelope_revisions')->value('revision'), $conn->table('public.article_receipts')->count(), $conn->table('public.article_outbox')->count()];
$armed = true;
$conn->listen(static function (QueryExecuted $query) use (&$armed): void {
    if ($armed && str_starts_with($query->sql, 'INSERT') && str_contains($query->sql, 'article_outbox')) {
        $armed = false;
        throw new RuntimeException('injected outbox failure');
    }
});
$fault = $operations->execute(handle: $handle, command: $command('consumer.fault.001', new Revision(2)), mutation: $mutation);
$after = [$conn->table('public.envelope_state')->value('effects'), $conn->table('public.envelope_revisions')->value('revision'), $conn->table('public.article_receipts')->count(), $conn->table('public.article_outbox')->count()];
if ($armed || $fault->isSuccess() || $before !== $after) {
    throw new RuntimeException('Atomic rollback failed');
}
$conn->table('public.envelope_authority')->update(['allowed' => false]);
$revoked = $operations->execute(handle: $handle, command: $original, mutation: $mutation);
if ($revoked->problem !== ProblemCode::AuthenticationRequired) {
    throw new RuntimeException('Revoked replay was admitted');
}
$conn->table('public.envelope_authority')->update(['allowed' => true]);
echo json_encode(['assertions' => ['create', 'current_admission', 'mutation', 'replay', 'atomic_rollback', 'revoked_replay'], 'counts' => $after], JSON_THROW_ON_ERROR);
