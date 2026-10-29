<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Queue\SerializesModels;
use SocKit\Schema\PanelModel;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid application');
}
$app->make(Kernel::class)->bootstrap();

final class PanelConsumerJob
{
    use SerializesModels;

    public function __construct(public PanelModel $model) {}
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
if (($argv[1] ?? '') === 'restore') {
    $payload = base64_decode($argv[2], true);
    if ($payload === false) {
        throw new RuntimeException('Invalid payload');
    }
    $job = unserialize($payload);
    if (! $job instanceof PanelConsumerJob) {
        throw new RuntimeException('Missing restored job');
    }
    $assert($job->model->getAttribute('external_key') === '0042', 'Wrong restored key');
    echo json_encode(['panel' => $job->model->getAttribute('panel'), 'connection' => $job->model->getConnectionName()], JSON_THROW_ON_ERROR);
    exit;
}
/** @return class-string<PanelModel> */
function generatedTarget(string $panel): string
{
    $class = 'Acme\\Models\\SocKit\\'.ucfirst($panel).'\\DemoTarget';
    if (! is_a($class, PanelModel::class, true)) {
        throw new RuntimeException('Generated model missing');
    }

    return $class;
}
function generatedInstance(mixed $model): PanelModel
{
    if (! $model instanceof PanelModel) {
        throw new RuntimeException('Generated model not hydrated');
    }

    return $model;
}
if (($argv[1] ?? '') === 'product_after') {
    $model = generatedInstance(generatedTarget('product')::query()->where('external_key', '42')->firstOrFail());
    $model->setAttribute('enabled', true);
    $model->save();
    $fresh = generatedInstance($model->fresh());
    $assert($fresh->getAttribute('enabled') === true && $fresh->getConnectionName() === 'owner_b', 'Product read/write failed after Article rollback');
    echo json_encode(['enabled' => $fresh->getAttribute('enabled'), 'connection' => $fresh->getConnectionName()], JSON_THROW_ON_ERROR);
    exit;
}
$ids = [];
foreach (['article', 'product'] as $panel) {
    $class = generatedTarget($panel);
    foreach (['42', '0042'] as $key) {
        $model = generatedInstance($class::query()->create(['installation' => 'host', 'panel' => 'acme.'.$panel, 'external_key' => $key]));
        $model->setAttribute('enabled', false);
        $model->save();
        $loaded = generatedInstance($class::query()->findOrFail($model->getKey()));
        $assert($loaded->getAttribute('external_key') === $key && $loaded->getAttribute('enabled') === false, 'CRUD mismatch');
        $ids[$panel][$key] = $model->getKey();
    }
}
foreach (['article', 'product', 'article'] as $panel) {
    $class = generatedTarget($panel);
    $model = generatedInstance($class::query()->findOrFail($ids[$panel]['0042']));
    $restored = unserialize(serialize(new PanelConsumerJob($model)));
    if (! $restored instanceof PanelConsumerJob) {
        throw new RuntimeException('Worker job not restored');
    }
    $assert($restored->model->getAttribute('panel') === 'acme.'.$panel, 'Worker restore mismatch');
    if ($panel === 'product') {
        try {
            $model->getConnection()->transaction(static function () use ($model): void {
                $model->setAttribute('external_key', 'rolled-back');
                $model->save();
                throw new RuntimeException('worker failure');
            });
        } catch (RuntimeException $error) {
            $assert($error->getMessage() === 'worker failure', 'Unexpected worker failure');
        }
        $assert(generatedInstance($model->fresh())->getAttribute('external_key') === '0042', 'Worker transaction leaked');
    }
}
$assert($app->make(DatabaseManager::class)->getDefaultConnection() === 'decoy', 'Default connection changed');
echo json_encode(['article' => base64_encode(serialize(new PanelConsumerJob(generatedInstance(generatedTarget('article')::query()->findOrFail($ids['article']['0042']))))), 'ids' => $ids], JSON_THROW_ON_ERROR);
