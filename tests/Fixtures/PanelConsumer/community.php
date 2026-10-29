<?php

declare(strict_types=1);

use Acme\Community\Scenario;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Invalid consumer application');
}
$app->make(Kernel::class)->bootstrap();
Scenario::run($app);
