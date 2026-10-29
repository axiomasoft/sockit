<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use SocKit\Panels\PanelDefinitions;
use SocKit\SocKitServiceProvider;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid application');
}
$queries = 0;
$app->afterResolving('events', static function (Dispatcher $events) use (&$queries): void {
    $events->listen(QueryExecuted::class, static function () use (&$queries): void {
        $queries++;
    });
});
$app->make(Kernel::class)->bootstrap();
$db = $app->make(DatabaseManager::class);
$panels = $app->make(PanelDefinitions::class);
echo json_encode(['discovered' => count($app->getProviders(SocKitServiceProvider::class)), 'panels' => array_keys($panels->all()), 'connections' => count($db->getConnections()), 'queries' => $queries], JSON_THROW_ON_ERROR);
