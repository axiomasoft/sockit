<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Modules;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid fixture application');
}
$kernel = $app->make(Kernel::class);
if ($argv[2] === 'snapshot') {
    $kernel->bootstrap();
    echo json_encode(['events' => $app->make(SocKitConfig::class)->events, 'modules' => $app->make(Modules::class)->declarations(),
        'connections' => count($app->make(DatabaseManager::class)->getConnections())], JSON_THROW_ON_ERROR);
    exit(0);
}
exit($kernel->call($argv[2]));
