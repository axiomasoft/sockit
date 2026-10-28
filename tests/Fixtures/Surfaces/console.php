<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use SocKit\Modules\Modules;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
assert($app instanceof Application);
$kernel = $app->make(Kernel::class);
if ($argv[2] === 'snapshot') {
    $kernel->bootstrap();
    echo json_encode(['panels' => $app->make(PanelDefinitions::class)->all(), 'settings' => $app->make(Modules::class)->settings(), 'surfaces' => $app->make(SurfaceDefinitions::class)->all(),
        'connections' => count($app->make(DatabaseManager::class)->getConnections())], JSON_THROW_ON_ERROR);
    exit(0);
}
exit($kernel->call($argv[2]));
