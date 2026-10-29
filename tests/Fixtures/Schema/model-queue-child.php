<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use SocKit\Schema\PanelModel;
use SocKit\Tests\Fixtures\Schema\GeneratedModelFixture;
use SocKit\Tests\Fixtures\Schema\ModelQueueJob;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

$input = Scenario::object(json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR));
$files = Scenario::object($input['files']);
spl_autoload_register(static function (string $class) use ($files): void {
    if (isset($files[$class])) {
        require Scenario::text($files[$class]);
    }
});
$target = PgTarget::fromEnvironment();
$pdo = $target->connect();
$config = $target->decoyConfiguration();
$owner = [...$config, 'database' => (string) PgTarget::query($pdo, 'SELECT current_database()')->fetchColumn()];
$capsule = new Manager;
foreach (['a' => 'driver_', 'b' => 'other_'] as $id => $prefix) {
    $capsule->addConnection(config: [...$owner, 'prefix' => $prefix], name: 'owner_'.$id);
}
$capsule->addConnection(config: $config, name: 'decoy');
$capsule->getDatabaseManager()->setDefaultConnection('decoy');
Model::setConnectionResolver($capsule->getDatabaseManager());
$payload = base64_decode(Scenario::text($input['payload']), true);
if (! is_string($payload)) {
    throw new RuntimeException('Invalid model payload');
}
$job = unserialize($payload);
if (! $job instanceof ModelQueueJob || ! $job->model instanceof PanelModel) {
    throw new RuntimeException('Missing restored emitted model');
}
$model = $job->model;
$relations = [];
foreach ($model->getRelations() as $name => $related) {
    $relations[$name] = $related instanceof Model ? $related->getConnectionName() : GeneratedModelFixture::many($related)->count();
}
echo json_encode(['class' => $model::class, 'id' => $model->getKey(), 'panel' => $model::binding()['panel'], 'table' => $model->getTable(), 'connection' => $model->getConnectionName(), 'relations' => $relations, 'default' => $capsule->getDatabaseManager()->getDefaultConnection()], JSON_THROW_ON_ERROR).PHP_EOL;
