<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SocKit\Tests\Fixtures\Schema\SchemaPanel;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

it('boots and config caches with zero SQL, no schema history and unavailable PostgreSQL', function (bool $pending): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/sockit-p37-boot-'.bin2hex(random_bytes(12));
    foreach (['bootstrap/cache', 'config', 'storage/framework', 'storage/logs', 'database/migrations', 'database/sockit/acme.articles'] as $directory) {
        $files->makeDirectory(path: $root.'/'.$directory, mode: 0700, recursive: true);
    }
    $files->put(path: $root.'/bootstrap/app.php', contents: <<<'BOOT'
<?php
return \Illuminate\Foundation\Application::configure(basePath: __DIR__.'/..')
    ->withProviders([\SocKit\SocKitServiceProvider::class, \SocKit\Tests\Fixtures\Schema\SchemaProvider::class])->create();
BOOT);
    $files->put(path: $root.'/config/sockit.php', contents: '<?php return '.var_export(['panels' => [SchemaPanel::class], 'modules' => ['enabled' => ['acme.demo-targets', 'acme.demo-links']]], true).';');
    $files->put(path: $root.'/config/database.php', contents: '<?php return '.var_export(['default' => 'missing', 'connections' => ['missing' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nonexistent_p37_test']]], true).';');
    if ($pending) {
        // Boot must not read any installed/generated history or execute pending migration PHP.
        $files->put(path: $root.'/database/migrations/2099_01_01_000000_pending.php', contents: "<?php throw new RuntimeException('Boot loaded a pending migration');");
        $files->put(path: $root.'/database/sockit/acme.articles/000001.json', contents: '{incomplete');
    }
    $run = static function (string $command) use ($root): array {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/PanelConsumer/boot.php', $root, $command],
            env: ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/config.php']);
        $process->mustRun();

        return Scenario::object(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    };
    try {
        $before = $run('snapshot');
        expect($before['panels'])->toBe(['acme.articles'])->and($before['queries'])->toBe(0)->and($before['connections'])->toBe(0);
        expect($run('config:cache'))->toBe($before)->and(is_file($root.'/bootstrap/cache/config.php'))->toBeTrue();
        expect($run('snapshot'))->toBe($before);
    } finally {
        $files->deleteDirectory($root);
    }
})->with([false, true]);
