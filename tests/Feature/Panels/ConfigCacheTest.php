<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SocKit\Tests\Fixtures\Panels\ArticlePanel;
use SocKit\Tests\Fixtures\Panels\ProductPanel;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

it('PANEL-03 runs real config cache then fresh process bootstrap with empty and populated panel registries', function (bool $enabled): void {
    $root = sys_get_temp_dir().'/sockit-p113-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    foreach (['bootstrap/cache', 'config', 'storage/framework', 'storage/logs'] as $directory) {
        $files->makeDirectory(path: $root.'/'.$directory, mode: 0700, recursive: true);
    }
    $bootstrap = <<<'PHPFILE'
<?php
return \Illuminate\Foundation\Application::configure(basePath: __DIR__.'/..')
    ->withProviders([
        \SocKit\SocKitServiceProvider::class,
        \SocKit\Tests\Fixtures\Modules\DemoProvider::class,
        \SocKit\Tests\Fixtures\Modules\OtherProvider::class,
    ])->create();
PHPFILE;
    $files->put(path: $root.'/bootstrap/app.php', contents: $bootstrap);
    $config = $enabled ? ['panels' => [ArticlePanel::class, ProductPanel::class], 'modules' => ['enabled' => ['demo.module', 'other.module'], 'settings' => ['demo.module' => ['limit' => 20]]]] : [];
    $files->put(path: $root.'/config/sockit.php', contents: '<?php return '.var_export($config, true).';');
    $console = dirname(__DIR__, 2).'/Fixtures/Panels/console.php';
    $run = static function (string $command) use ($root, $console): string {
        $process = new Process([PHP_BINARY, $console, $root, $command], env: ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/config.php']);
        $process->mustRun();

        return $process->getOutput();
    };
    try {
        $before = $run('snapshot');
        $run('config:cache');
        expect(is_file($root.'/bootstrap/cache/config.php'))->toBeTrue();
        $after = $run('snapshot');
        expect($after)->toBe($before);
        $snapshot = json_decode($after, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($snapshot));
        expect($snapshot['settings'])->toBe($enabled ? ['demo.module' => ['limit' => 20], 'other.module' => []] : [])
            ->and($snapshot['connections'])->toBe(0)
            ->and($snapshot['panels'])->toHaveCount($enabled ? 2 : 0);
        if ($enabled) {
            $panels = Scenario::object($snapshot['panels']);
            $article = Scenario::object($panels['host.articles']);
            $settings = Scenario::object($article['moduleSettings']);
            $demo = Scenario::object($settings['demo.module']);
            expect($demo['limit'])->toBe(30);
        }
    } finally {
        $files->deleteDirectory($root);
    }
})->with([false, true]);
