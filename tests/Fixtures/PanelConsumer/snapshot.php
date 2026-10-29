<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use SocKit\Modules\Modules;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\ModelBindings;
use SocKit\Schema\PanelModel;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\SocKitServiceProvider;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
assert($app instanceof Application);
$queries = 0;
$app->make('events')->listen(QueryExecuted::class, static function () use (&$queries): void {
    $queries++;
});
$app->make(Kernel::class)->bootstrap();
$bindings = [];
foreach ($app->make(PanelDefinitions::class)->all() as $panel) {
    $schema = new PanelSchemaCompiler()->compile(panel: $panel, components: $app->make(Modules::class)->schemaComponents());
    new ModelBindings()->validate($schema);
    foreach ($schema->storage->models as $entity => $class) {
        assert(is_a($class, PanelModel::class, true));
        $model = new $class;
        $bindings[$entity] = [$model->getTable(), $model->getConnectionName(), $model::binding()['panel']];
    }
}
echo json_encode(['discovered' => count($app->getProviders(SocKitServiceProvider::class)), 'panels' => array_keys($app->make(PanelDefinitions::class)->all()),
    'bindings' => $bindings, 'default' => $app->make(DatabaseManager::class)->getDefaultConnection(), 'connections' => count($app->make(DatabaseManager::class)->getConnections()), 'queries' => $queries], JSON_THROW_ON_ERROR);
