<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Modules;
use SocKit\Surfaces\Contracts\TargetResolver;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Expected fixture app');
}
$kernel = $app->make(Kernel::class);
if ($argv[2] === 'snapshot') {
    $kernel->bootstrap();
    echo json_encode(['declarations' => $app->make(Modules::class)->declarations(), 'fallback' => $app->make(SocKitConfig::class)->resolvers->targets,
        'resolver' => $app->make(TargetResolver::class)::class, 'connections' => count($app->make(DatabaseManager::class)->getConnections())], JSON_THROW_ON_ERROR);
    exit(0);
}
exit($kernel->call($argv[2]));
