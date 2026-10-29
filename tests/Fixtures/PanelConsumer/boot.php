<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use SocKit\Panels\PanelDefinitions;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
assert($app instanceof Application);
$queries = 0;
$app->make('events')->listen(QueryExecuted::class, static function () use (&$queries): void {
    $queries++;
});
// Any accidental PDO acquisition, including raw SQL invisible to QueryExecuted, fails.
$app->bind('db.connector.pgsql', static function (): never {
    throw new RuntimeException('Boot attempted PostgreSQL access');
});
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$panels = $app->make(PanelDefinitions::class)->all();
$models = [];
foreach ($panels as $panel) {
    foreach ($panel->storage->models as $model) {
        $models[] = class_exists($model, autoload: false);
    }
}
if ($argv[2] === 'config:cache') {
    $exit = $kernel->call('config:cache');
    if ($exit !== 0) {
        throw new RuntimeException('Real config cache failed');
    }
}
$connections = $app->make(DatabaseManager::class)->getConnections();
if ($connections !== [] || $queries !== 0 || in_array(true, $models, true)) {
    throw new RuntimeException('Boot performed persistence discovery');
}
echo json_encode(['panels' => array_keys($panels), 'queries' => $queries, 'connections' => count($connections), 'models_loaded' => $models], JSON_THROW_ON_ERROR);
