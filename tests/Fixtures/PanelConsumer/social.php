<?php

declare(strict_types=1);

use Acme\Social\Job;
use Acme\Social\Runtime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Modules\Modules;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\PanelDefinitions;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Posts\Posts;
use SocKit\Reactions\Reactions;
use SocKit\Schema\ModelBindings;
use SocKit\Schema\PanelModel;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;

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
$mode = $argv[1] ?? '';
$result = [];
if ($mode === 'boot') {
    $modules = $app->make(Modules::class);
    Runtime::check(array_keys($modules->manifests()) === ['sockit.reactions'] && ! class_exists('SocKit\\Posts\\Internal\\PostsModule', false), 'Reactions-only boot loaded posts');
    foreach ($app->make(PanelDefinitions::class)->all() as $panel) {
        $schema = new PanelSchemaCompiler()->compile(panel: $panel, components: $modules->schemaComponents());
        new ModelBindings()->validate($schema);
        Runtime::check(array_keys($schema->storage->models) === ['outbox', 'reaction_counts', 'reactions', 'receipts', 'surface_instances', 'tombstones'], 'Production model map mismatch');
    }
    $result = ['panels' => array_keys($app->make(PanelDefinitions::class)->all()), 'queries' => $queries, 'connections' => count($app->make(DatabaseManager::class)->getConnections())];
} elseif ($mode === 'smoke') {
    $result = $runtime->smoke();
} elseif ($mode === 'restore') {
    $payload = base64_decode($argv[2], true);
    if ($payload === false) {
        throw new RuntimeException('Invalid job payload');
    }
    $job = unserialize($payload);
    Runtime::check($job instanceof Job, 'Job not restored');
    if (! $job instanceof Job) {
        throw new RuntimeException('Job missing');
    }
    Runtime::check($job->model->getAttribute('active') === true && $job->model->getAttribute('target_key') === '42', 'Fresh process model restoration mismatch');
    $result = ['panel' => $job->model->getAttribute('panel'), 'connection' => $job->model->getConnectionName(), 'custom' => str_ends_with($job->model::class, '\\CustomReaction')];
} elseif (in_array($mode, ['posts-on', 'posts-off'], true)) {
    $h = $runtime->handle();
    $target = new TargetRef(host: new HostEntityRef(type: 'post', value: '019ed1f7-7c00-7000-8000-000000000001'), incarnation: new Incarnation(1));
    $available = false;
    try {
        $resolved = $app->make(OperationHandles::class)->resolveTarget(handle: $h, target: $target);
        Runtime::check($resolved->target->equals($target), 'Wrong post resolution');
        $available = true;
    } catch (ProviderUnavailableException) {
        $available = false;
    } catch (Throwable $error) {
        // An enabled owner resolves the missing post and denies its existence. A disabled
        // capability fails before reaching the owner. Neither denial is a successful read.
        if (! $error instanceof ProvidesProblem || $error->problem() !== ProblemCode::ContentHidden) {
            throw $error;
        }
        Runtime::check(in_array('sockit.posts', $h->panel->modules, true), 'Unexpected missing post resolver');
        $available = true;
    }
    Runtime::check($available === ($mode === 'posts-on'), 'Post target capability selection incorrect');
    // Product must never gain Article's optional capability.
    try {
        $app->make(OperationHandles::class)->resolveTarget(handle: $runtime->handle('product'), target: $target);
        throw new RuntimeException('Product unexpectedly gained posts target');
    } catch (ProviderUnavailableException) {
    }
    $result = ['post_capability' => $available];
} elseif ($mode === 'seed-post') {
    $h = $runtime->handle();
    $api = $app->make(Posts::class);
    $post = new PostCommand(surface: $h->surface, body: 'Consumer retained draft');
    $command = new CommandEnvelope(key: new CommandKey('consumer.post.draft.1'), namespace: new CommandNamespace(installation: $h->actor->installation, panel: $h->surface->panel, integration: 'acme.client', commandType: 'posts.create'), digest: $api->digest(handle: $h, post: $post));
    $created = $api->create(handle: $h, post: $post, command: $command);
    Runtime::success($created->operation);
    Runtime::check($created->publication === PublicationState::Pending && ! $created->isPublished(), 'Draft incorrectly published');
    $postClass = $argv[2];
    $revisionClass = $argv[3];
    if (! is_a($postClass, PanelModel::class, true) || ! is_a($revisionClass, PanelModel::class, true)) {
        throw new RuntimeException('Generated production posts models missing');
    }
    $model = $postClass::query()->firstOrFail();
    $revision = $revisionClass::query()->firstOrFail();
    $linked = $revision->getRelationValue('post');
    $children = $model->getRelationValue('postRevisionsPost');
    Runtime::check($linked instanceof PanelModel && $linked->getKey() === $model->getKey() && $children instanceof Collection && $children->count() === 1 && $children->first()?->getKey() === $revision->getKey(), 'Production generated relations failed');
    $job = unserialize(serialize(new Job($revision)));
    Runtime::check($job instanceof Job && $job->model->getKey() === $revision->getKey() && $job->model->getConnectionName() === 'owner_a', 'Production posts queue restore failed');
    $result = ['relations' => true];
} elseif ($mode === 'reactions-off') {
    $h = $runtime->handle();
    $view = $app->make(Reactions::class)->summary(handle: $h, target: $runtime->reaction($h)->target);
    Runtime::check($view->counts === null && $view->problem !== null, 'Disabled reactions disclosed counts');
    $runtime->count($runtime->handle('product'), $runtime->reaction($runtime->handle('product')), 1);
    $result = ['denied' => true];
} elseif (in_array($mode, ['read', 'product-after'], true)) {
    $h = $runtime->handle($mode === 'read' ? 'article' : 'product');
    $reaction = $runtime->reaction($h);
    if ($mode === 'product-after') {
        Runtime::success($app->make(Reactions::class)->remove(handle: $h, reaction: $reaction, command: $runtime->command($h, $reaction, 'product.after.remove', 'remove', 1)));
        Runtime::success($app->make(Reactions::class)->add(handle: $h, reaction: $reaction, command: $runtime->command($h, $reaction, 'product.after.readd', revision: 2)));
    }
    $runtime->count($h, $reaction, 1);
    $result = ['like' => 1, 'revision' => 3];
} else {
    throw new RuntimeException('Unknown social consumer mode');
}
echo json_encode($result, JSON_THROW_ON_ERROR);
