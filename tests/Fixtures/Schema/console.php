<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\PanelSchemaCompiler;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
assert($app instanceof Application);
$kernel = $app->make(Kernel::class);
$queries = 0;
$app->make('events')->listen(QueryExecuted::class, static function () use (&$queries): void {
    $queries++;
});
if ($argv[2] === 'snapshot') {
    $kernel->bootstrap();
    $schema = new PanelSchemaCompiler()->compile(panel: $app->make(PanelDefinitions::class)->get(new PanelId('acme.articles')), components: $app->make(Modules::class)->schemaComponents());
    echo json_encode(['schema' => $schema->jsonSerialize(), 'connections' => count($app->make(DatabaseManager::class)->getConnections()), 'queries' => $queries], JSON_THROW_ON_ERROR);
    exit(0);
}
$exit = $kernel->call($argv[2]);
if ($queries !== 0 || $app->make(DatabaseManager::class)->getConnections() !== []) {
    throw new RuntimeException('Config cache resolved a database');
}
exit($exit);
