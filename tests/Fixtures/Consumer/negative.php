<?php

declare(strict_types=1);

use Acme\EnvelopeMutation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid application');
}
$app->make(Kernel::class)->bootstrap();
$conn = $app->make(DatabaseManager::class)->connection('owner_a');
$ref = new SurfaceRef(panel: new PanelId('acme.a'), kind: new SurfaceKindId('acme.runtime'), host: new HostEntityRef(type: 'article', value: '42'), incarnation: new Incarnation(1));
$handle = $app->make(OperationHandles::class)->forSurface($ref);
$mutation = new EnvelopeMutation($conn);
$operations = $app->make(Operations::class);
$revision = new Revision(1);
$command = new CommandEnvelope(key: new CommandKey('consumer.missing.target'), namespace: new CommandNamespace(installation: $handle->actor->installation, panel: $ref->panel, integration: 'acme.client', commandType: EnvelopeMutation::TYPE), digest: $operations->digest(handle: $handle, mutation: $mutation, expectedRevision: $revision), expectedRevision: $revision);
$before = [$conn->table('panel_a.envelope_state')->where('target', '42')->value('effects'), $conn->table('panel_a.a_receipts')->count(), $conn->table('panel_a.a_outbox')->count()];
$result = $operations->execute(handle: $handle, command: $command, mutation: $mutation);
$after = [$conn->table('panel_a.envelope_state')->where('target', '42')->value('effects'), $conn->table('panel_a.a_receipts')->count(), $conn->table('panel_a.a_outbox')->count()];
if ($result->isSuccess() || $result->problem !== ProblemCode::ProviderUnavailable || $before !== $after) {
    throw new RuntimeException('Missing target resolver was not denied safely: '.json_encode($result->toWire(), JSON_THROW_ON_ERROR));
}
echo json_encode(['assertion' => 'missing_target_denied', 'problem' => $result->problem->value, 'counts' => $after], JSON_THROW_ON_ERROR);
