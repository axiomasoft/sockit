<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SocKit\Tests\Fixtures\Runtime\RuntimeSurface;
use SocKit\Tests\Fixtures\Runtime\TargetAdapter;
use SocKit\Tests\Fixtures\Runtime\TargetPanel;
use Symfony\Component\Process\Process;

it('keeps FQCN target declarations and scoped host binding through actual config cache and fresh bootstrap without SQL', function (): void {
    $root = sys_get_temp_dir().'/sockit-p23-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    foreach (['bootstrap/cache', 'config', 'storage/framework', 'storage/logs'] as $directory) {
        $files->makeDirectory(path: $root.'/'.$directory, mode: 0700, recursive: true);
    }
    $bootstrap = <<<'PHPFILE'
<?php
return \Illuminate\Foundation\Application::configure(basePath: __DIR__.'/..')
    ->withProviders([\SocKit\SocKitServiceProvider::class, \SocKit\Tests\Fixtures\Runtime\TargetProvider::class])->create();
PHPFILE;
    $files->put(path: $root.'/bootstrap/app.php', contents: $bootstrap);
    $config = ['panels' => [TargetPanel::class], 'surfaces' => [RuntimeSurface::class], 'modules' => ['enabled' => ['acme.runtime', 'acme.other']], 'resolvers' => ['targets' => TargetAdapter::class]];
    $files->put(path: $root.'/config/sockit.php', contents: '<?php return '.var_export($config, true).';');
    $console = dirname(__DIR__, 2).'/Fixtures/Runtime/target-console.php';
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
        if (! is_array($snapshot)) {
            throw new RuntimeException('Expected snapshot');
        }
        expect($snapshot['connections'])->toBe(0)->and($snapshot['resolver'])->toBe(TargetAdapter::class);
    } finally {
        $files->deleteDirectory($root);
    }
});
