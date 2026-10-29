<?php

declare(strict_types=1);

use Acme\Groups\ContentScenario;
use Acme\Social\Job;
use Acme\Social\Runtime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\GroupRef;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Groups;
use SocKit\Modules\Modules;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OutboxRelay;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\ModelBindings;
use SocKit\Schema\PanelModel;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid consumer application');
}
$queries = 0;
$app->make('events')->listen(QueryExecuted::class, static function () use (&$queries): void {
    $queries++;
});
$app->make(Kernel::class)->bootstrap();
$runtime = new Runtime($app);
$api = $app->make(Groups::class);
$mode = $argv[1] ?? '';

function groupCommand(Groups $api, OperationHandle $handle, GroupCommand|MembershipCommand $input, string $type, ?int $revision = null): CommandEnvelope
{
    $expected = $revision === null ? null : new Revision($revision);

    return new CommandEnvelope(key: new CommandKey('consumer.'.$type.'.0001'), namespace: new CommandNamespace(
        installation: $handle->actor->installation, panel: $handle->surface->panel, integration: 'acme.client', commandType: $type),
        digest: $api->digest($handle, $input, $expected), expectedRevision: $expected);
}

function groupSubject(OperationHandle $handle, ResourceRef $group): AudienceSubject
{
    return new AudienceSubject(key: 'consumer.group', spec: AudienceSpec::group(new GroupRef($group->id)),
        author: null, surface: $handle->surface, installation: $handle->actor->installation);
}

$result = [];
if ($mode === 'boot') {
    $bindings = [];
    foreach ($app->make(PanelDefinitions::class)->all() as $panel) {
        $schema = new PanelSchemaCompiler()->compile($panel, $app->make(Modules::class)->schemaComponents());
        $bindings[$panel->id->value] = $schema->storage->models;
    }
    $result = ['models' => $bindings, 'queries' => $queries, 'connections' => count($app->make(DatabaseManager::class)->getConnections()),
        'default' => $app->make(DatabaseManager::class)->getDefaultConnection()];
} elseif ($mode === 'content') {
    try {
        $result = ContentScenario::run($app);
    } catch (Throwable $error) {
        fwrite(STDERR, (string) $error);
        throw $error;
    }
} elseif ($mode === 'smoke') {
    foreach ($app->make(PanelDefinitions::class)->all() as $panel) {
        new ModelBindings()->validate(new PanelSchemaCompiler()->compile($panel, $app->make(Modules::class)->schemaComponents(), modelNamespace: 'Acme\\'));
    }
    $resources = [];
    $gate = $app->make(AudienceGate::class);
    foreach (['article', 'product'] as $slug) {
        $h = $runtime->handle($slug);
        $input = new GroupCommand(JoinPolicy::Auto, 'Chess 0042');
        $command = groupCommand($api, $h, $input, 'groups.create');
        $created = $api->create($h, $input, $command);
        Runtime::success($created);
        $resource = $created->resource ?? throw new RuntimeException('Missing group resource');
        $resources[$slug] = $resource;
        Runtime::check($api->create($h, $input, $command)->toWire() === $created->toWire(), 'Group replay mismatch');
        Runtime::check($api->read($h, $resource)->name === 'Chess 0042', 'Group public read failed');
        Runtime::check(! $gate->read($h->actor, groupSubject($h, $resource))->canRead(), 'Creator acquired implicit membership');
        $join = new MembershipCommand($resource, 'join');
        $joined = $api->join($h, $join, groupCommand($api, $h, $join, 'groups.join', 1));
        Runtime::success($joined);
        Runtime::check($joined->revision?->value === 2 && $gate->read($h->actor, groupSubject($h, $resource))->canRead(), 'Active membership missing');
        $runtime->count($h, $runtime->reaction($h), 1);
    }
    $payloads = [];
    foreach (['article', 'product', 'article'] as $slug) {
        $h = $runtime->handle($slug);
        $resource = $resources[$slug];
        $groupClass = $h->panel->storage->models['groups'];
        $memberClass = $h->panel->storage->models['group_members'];
        if (! is_a($groupClass, PanelModel::class, true) || ! is_a($memberClass, PanelModel::class, true)) {
            throw new RuntimeException('Generated concrete groups models missing');
        }
        $group = $groupClass::query()->whereKey($resource->id)->firstOrFail();
        $member = $memberClass::query()->where('group_id', $resource->id)->firstOrFail();
        $parent = $member->getRelationValue('group');
        $children = $group->getRelationValue('groupMembersGroup');
        Runtime::check($parent instanceof PanelModel && $parent::class === $groupClass && $parent->getKey() === $resource->id
            && $children instanceof Collection && $children->count() === 1 && $children->first()?->getKey() === $member->getKey(), 'Groups FK relations leaked');
        Runtime::check($group->getAttribute('revision') === 2 && $group->getAttribute('membership_generation') === 2
            && $group->getAttribute('created_at') instanceof DateTimeImmutable && $member->getAttribute('state') === 'active'
            && $member->getAttribute('revision') === 1 && $member->getAttribute('consent_generation') === null
            && $member->getAttribute('consent_token') === '1' && $member->getAttribute('updated_at') instanceof DateTimeImmutable, 'Groups casts failed');
        foreach ([$group, $member] as $model) {
            Runtime::check($model->getConnectionName() === $h->panel->storage->connection && str_contains($model->getTable(), $slug.'_'), 'Wrong panel binding');
            $payload = base64_encode(serialize(new Job($model->withoutRelations())));
            $restored = unserialize((string) base64_decode($payload, true));
            Runtime::check($restored instanceof Job && $model::class === $restored->model::class
                && $restored->model->getKey() === $model->getKey() && $restored->model->getConnectionName() === $h->panel->storage->connection, 'A-B-A queue restore leaked');
            $payloads[] = ['payload' => $payload, 'class' => $model::class, 'key' => $model->getKey(), 'connection' => $model->getConnectionName()];
        }
        if ($slug === 'article') {
            $source = new ReflectionClass($groupClass)->getFileName();
            Runtime::check(is_string($source) && str_contains((string) file_get_contents($source), 'P5.6 consumer custom group marker'), 'Consumer custom group file lost');
        }
    }
    $a = $runtime->handle();
    $b = $runtime->handle('product');
    Runtime::check(! $gate->read($b->actor, groupSubject($b, $resources['article']))->canRead(), 'Cross-panel membership disclosed');
    $runtime->connection()->table('public.p48_targets')->where('type', 'article')->update(['visible' => false]);
    Runtime::check(! $gate->read($a->actor, groupSubject($a, $resources['article']))->canRead(), 'Current host revocation ignored');
    $runtime->connection()->table('public.p48_targets')->where('type', 'article')->update(['visible' => true]);
    $result = ['resources' => array_map(static fn (ResourceRef $r): string => $r->id, $resources), 'queue' => $payloads,
        'assertions' => ['public_create_join_read', 'no_creator_membership', 'current_host_admission', 'panel_isolation', 'models_casts_relations', 'worker_A_B_A']];
} elseif ($mode === 'restore') {
    $job = unserialize((string) base64_decode($argv[2], true));
    Runtime::check($job instanceof Job, 'Fresh process job missing');
    if (! $job instanceof Job) {
        throw new RuntimeException('Invalid job');
    }
    $result = ['class' => $job->model::class, 'key' => $job->model->getKey(), 'connection' => $job->model->getConnectionName()];
} elseif ($mode === 'disabled') {
    $h = $runtime->handle();
    $resource = new ResourceRef($h->surface->panel, ResourceKind::Group, $argv[2]);
    Runtime::check(! $app->make(AudienceGate::class)->read($h->actor, groupSubject($h, $resource))->canRead(), 'Disabled membership disclosed');
    try {
        $api->read($h, $resource);
        throw new RuntimeException('Disabled group disclosed');
    } catch (Throwable $error) {
        Runtime::check($error instanceof ProvidesProblem && $error->problem() === ProblemCode::ModuleInvalid, 'Disabled group failed for wrong reason');
    }
    $deliveries = $app->make(OutboxRelay::class)->poll($h);
    Runtime::check(count($deliveries) === 2, 'Pending group events missing');
    foreach ($deliveries as $delivery) {
        Runtime::check($delivery->status === 'quarantine' && $delivery->reason === 'outbox.consumer_unavailable', 'Disabled event not explicitly quarantined');
    }
    $result = ['membership_denied' => true, 'quarantined' => count($deliveries)];
} elseif ($mode === 'reenabled') {
    $h = $runtime->handle();
    $resource = new ResourceRef($h->surface->panel, ResourceKind::Group, $argv[2]);
    Runtime::check($api->read($h, $resource)->revision->value === 2 && $app->make(AudienceGate::class)->read($h->actor, groupSubject($h, $resource))->canRead(), 'Re-enabled data inaccessible');
    Runtime::check($app->make(OutboxRelay::class)->poll($h) === [], 'Quarantined event silently redelivered');
    $result = ['preserved' => true];
} elseif ($mode === 'product-after') {
    $h = $runtime->handle('product');
    $resource = new ResourceRef($h->surface->panel, ResourceKind::Group, $argv[2]);
    Runtime::check($api->read($h, $resource)->revision->value === 2, 'Neighbour data changed');
    $leave = new MembershipCommand($resource, 'leave');
    Runtime::success($api->leave($h, $leave, groupCommand($api, $h, $leave, 'groups.leave', 2)));
    $result = ['revision' => $api->read($h, $resource)->revision->value];
} else {
    throw new RuntimeException('Unknown groups mode');
}
echo json_encode($result, JSON_THROW_ON_ERROR);
