<?php

declare(strict_types=1);

use Acme\EnvelopeMutation;
use Acme\PgTarget;
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
    throw new RuntimeException('Invalid consumer application');
}
$app->make(Kernel::class)->bootstrap();
$db = $app->make(DatabaseManager::class);
$conn = $db->connection('owner_a');
$ref = new SurfaceRef(panel: new PanelId('acme.a'), kind: new SurfaceKindId('acme.runtime'), host: new HostEntityRef(type: 'article', value: '42'), incarnation: new Incarnation(1));
$handle = $app->make(OperationHandles::class)->forSurface($ref);
$api = $app->make(SurfaceInstances::class);
$operations = $app->make(Operations::class);
$ns = static fn (string $type): CommandNamespace => new CommandNamespace(installation: $handle->actor->installation, panel: $ref->panel, integration: 'acme.client', commandType: $type);
$lifecycle = static fn (string $action, ?Revision $revision = null): CommandEnvelope => new CommandEnvelope(key: new CommandKey('consumer.lifecycle.'.$action), namespace: $ns('sockit.surfaces.'.$action), digest: $api->digest(handle: $handle, action: $action, expectedRevision: $revision), expectedRevision: $revision);
$assertions = [];
$check = static function (string $id, bool $ok) use (&$assertions): void {
    if (! $ok) {
        throw new RuntimeException('Consumer assertion failed: '.$id);
    } $assertions[] = $id;
};
$created = $api->create(handle: $handle, command: $lifecycle('create'));
$check('create', $created->isSuccess());
$resource = $api->get($handle);
$check('read', $resource->revision->value === 1);
$conn->table('panel_a.envelope_revisions')->insert(['target' => '42', 'resource' => $created->resource?->id, 'revision' => 1]);
$refB = new SurfaceRef(panel: new PanelId('acme.b'), kind: new SurfaceKindId('acme.runtime'), host: new HostEntityRef(type: 'article', value: '42'), incarnation: new Incarnation(1));
$handleB = $app->make(OperationHandles::class)->forSurface($refB);
$nsB = static fn (string $type): CommandNamespace => new CommandNamespace(installation: $handleB->actor->installation, panel: $refB->panel, integration: 'acme.client', commandType: $type);
$createB = new CommandEnvelope(key: new CommandKey('consumer.b.create'), namespace: $nsB('sockit.surfaces.create'), digest: $api->digest(handle: $handleB, action: 'create'));
$createdB = $api->create(handle: $handleB, command: $createB);
$check('create_b', $createdB->isSuccess() && $api->get($handleB)->revision->value === 1);
$connB = $db->connection('owner_b');
$connB->table('panel_b.envelope_revisions')->insert(['target' => '42', 'resource' => $createdB->resource?->id, 'revision' => 1]);
$mutationB = new EnvelopeMutation(connection: $connB, schema: 'panel_b');
$commandB = new CommandEnvelope(key: new CommandKey('consumer.b.mutate'), namespace: $nsB(EnvelopeMutation::TYPE), digest: $operations->digest(handle: $handleB, mutation: $mutationB, expectedRevision: new Revision(1)), expectedRevision: new Revision(1));
$check('mutation_b', $operations->execute(handle: $handleB, command: $commandB, mutation: $mutationB)->isSuccess());
$bBefore = [$connB->table('panel_b.envelope_state')->where('target', '42')->value('effects'), $connB->table('panel_b.envelope_revisions')->where('target', '42')->value('revision'), $connB->table('panel_b.b_receipts')->count(), $connB->table('panel_b.b_outbox')->count()];
$mutation = new EnvelopeMutation($conn);
$command = static fn (string $key, Revision $expected): CommandEnvelope => new CommandEnvelope(key: new CommandKey($key), namespace: $ns(EnvelopeMutation::TYPE), digest: $operations->digest(handle: $handle, mutation: $mutation, expectedRevision: $expected), expectedRevision: $expected);
$original = $command('consumer.original.1', new Revision(1));
$first = $operations->execute(handle: $handle, command: $original, mutation: $mutation);
$check('mutation', $first->isSuccess());
$lookup = $operations->lookup(handle: $handle, namespace: $original->namespace, key: $original->key);
$check('lookup', $lookup?->toWire() === $first->toWire());
$check('replay', $operations->execute(handle: $handle, command: $original, mutation: $mutation)->toWire() === $first->toWire());
$mutation->value = 2;
$check('payload_conflict', $operations->execute(handle: $handle, command: $command('consumer.original.1', new Revision(1)), mutation: $mutation)->problem === ProblemCode::IdempotencyConflict);
$mutation->value = 1;
$check('cas', $operations->execute(handle: $handle, command: $command('consumer.cas.loser', new Revision(1)), mutation: $mutation)->problem === ProblemCode::RevisionConflict);
$before = [$conn->table('panel_a.envelope_state')->where('target', '42')->value('effects'), $conn->table('panel_a.envelope_revisions')->where('target', '42')->value('revision'), $conn->table('panel_a.a_receipts')->count(), $conn->table('panel_a.a_outbox')->count()];
$decoy = PgTarget::fromEnvironment()->connect(decoy: true);
$snapshot = static function (PDO $pdo, string $prefix): string {
    $sets = [];
    foreach (['surface_instances', 'receipts', 'outbox', 'tombstones'] as $entity) {
        $rows = PgTarget::query($pdo, 'SELECT row_to_json(t)::text FROM '.$prefix.$entity.' t')->fetchAll(PDO::FETCH_COLUMN);
        sort($rows);
        $sets[$entity] = $rows;
    }

    return hash('sha256', json_encode($sets, JSON_THROW_ON_ERROR));
};
$bSnapshot = $snapshot($connB->getPdo(), 'panel_b.other_b_');
$decoySnapshot = $snapshot($decoy, 'panel_a.driver_a_');
$armed = true;
$conn->listen(static function (QueryExecuted $query) use (&$armed): void {
    if ($armed && str_starts_with($query->sql, 'INSERT') && str_contains($query->sql, 'a_outbox')) {
        $armed = false;
        throw new RuntimeException('fault after outbox');
    }
});
$fault = $operations->execute(handle: $handle, command: $command('consumer.fault.1', new Revision(2)), mutation: $mutation);
$after = [$conn->table('panel_a.envelope_state')->where('target', '42')->value('effects'), $conn->table('panel_a.envelope_revisions')->where('target', '42')->value('revision'), $conn->table('panel_a.a_receipts')->count(), $conn->table('panel_a.a_outbox')->count()];
$bAfter = [$connB->table('panel_b.envelope_state')->where('target', '42')->value('effects'), $connB->table('panel_b.envelope_revisions')->where('target', '42')->value('revision'), $connB->table('panel_b.b_receipts')->count(), $connB->table('panel_b.b_outbox')->count()];
$check('rollback', ! $armed && ! $fault->isSuccess() && $before === $after && $bBefore === $bAfter && $bSnapshot === $snapshot($connB->getPdo(), 'panel_b.other_b_') && $decoySnapshot === $snapshot($decoy, 'panel_a.driver_a_'));
$deliveries = $app->make(OutboxRelay::class)->poll($handle);
fwrite(STDERR, 'delivery results='.json_encode($deliveries, JSON_THROW_ON_ERROR).PHP_EOL);
$confirmed = array_filter($deliveries, static fn ($r): bool => $r->isSuccess());
$check('relay', count($deliveries) === 2 && count($confirmed) === 1);
$inbox = $conn->selectOne('SELECT count(*) AS n FROM acme_consumer.effects');
$check('inbox', $inbox instanceof stdClass && isset($inbox->n) && $inbox->n === 1);
$check('relay_repeat', $app->make(OutboxRelay::class)->poll($handle) === []);
$conn->table('panel_a.envelope_authority')->update(['allowed' => false]);
$revoked = $operations->execute(handle: $handle, command: $original, mutation: $mutation);
fwrite(STDERR, 'revoked result='.json_encode($revoked->toWire(), JSON_THROW_ON_ERROR).PHP_EOL);
$check('revoked_replay', $revoked->problem === ProblemCode::AuthenticationRequired);
$conn->table('panel_a.envelope_authority')->update(['allowed' => true]);
$archive = $api->archive(handle: $handle, command: $lifecycle('archive', new Revision(1)));
$check('archive', $archive->isSuccess());
$resume = $api->resume(handle: $handle, command: $lifecycle('resume', new Revision(2)));
$check('resume', $resume->isSuccess());
$deleted = $api->delete(handle: $handle, command: $lifecycle('delete', new Revision(3)));
$check('delete', $deleted->isSuccess() && $conn->table('panel_a.a_tombstones')->count() === 1);
echo json_encode(['assertions' => $assertions, 'counts' => $after, 'deliveries' => count($deliveries)], JSON_THROW_ON_ERROR);
