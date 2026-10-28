<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use SocKit\Tests\Integration\Support\PgTarget;

$target = PgTarget::fromEnvironment();
$pdo = $target->connect();
$config = $target->decoyConfiguration();
$config['database'] = (string) PgTarget::query($pdo, 'SELECT current_database()')->fetchColumn();
$capsule = new Manager;
$capsule->addConnection(config: [...$config, 'prefix' => 'changed_'], name: 'owner_a');
DB::swap($capsule->getDatabaseManager());
$capsule->getConnection('owner_a')->statement('SET search_path TO panel_b, public');
// Fail if the generated file tries to load current package helpers, registries or module fixtures.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'SocKit\\Schema\\') || str_starts_with($class, 'SocKit\\Modules\\') || str_starts_with($class, 'SocKit\\Panels\\') || str_starts_with($class, 'SocKit\\Tests\\Fixtures\\Schema\\')) {
        throw new RuntimeException('Latest schema/module dependency: '.$class);
    }
}, prepend: true);
$migration = require $argv[1];
$action = $argv[2];
if (! $migration instanceof Migration || ! in_array($action, ['up', 'down'], true)) {
    throw new RuntimeException('Invalid snapshot child arguments');
}
$call = [$migration, $action];
if (! is_callable($call)) {
    throw new RuntimeException('Missing snapshot action');
}
Closure::fromCallable($call)();
fwrite(STDOUT, 'fresh_snapshot_'.$action.'=ok latest_helpers_unavailable=true prefix_changed=true search_path_changed=true'.PHP_EOL);
