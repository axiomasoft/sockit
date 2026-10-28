<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SocKit\Tests\Fixtures\Surfaces\CrmTaskDiscussionSurface;
use SocKit\Tests\Fixtures\Surfaces\CrmTeamArticleSurface;
use SocKit\Tests\Fixtures\Surfaces\EdufyClassFeedSurface;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

it('runs real config cache and fresh process bootstrap for empty and populated surface registries', function (bool $enabled): void {
    $root = sys_get_temp_dir().'/sockit-p110-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    foreach (['bootstrap/cache', 'config', 'storage/framework', 'storage/logs'] as $directory) {
        $files->makeDirectory(path: $root.'/'.$directory, mode: 0700, recursive: true);
    }
    $bootstrap = <<<'PHPFILE'
<?php
return \Illuminate\Foundation\Application::configure(basePath: __DIR__.'/..')
    ->withProviders([
        \SocKit\SocKitServiceProvider::class,
        \SocKit\Tests\Fixtures\Surfaces\SocialServiceProvider::class,
        \SocKit\Tests\Fixtures\Surfaces\PanelServiceProvider::class,
    ])->create();
PHPFILE;
    $files->put(path: $root.'/bootstrap/app.php', contents: $bootstrap);
    $config = ['privacy' => ['floor' => Scenario::floorWire()], 'surfaces' => $enabled ? [EdufyClassFeedSurface::class, CrmTaskDiscussionSurface::class, CrmTeamArticleSurface::class] : [], 'modules' => ['enabled' => ['social.posts', 'social.reactions']]];
    $files->put(path: $root.'/config/sockit.php', contents: '<?php return '.var_export($config, true).';');
    $console = dirname(__DIR__, 2).'/Fixtures/Surfaces/console.php';
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
        expect($snapshot['connections'])->toBe(0)->and($snapshot['surfaces'])->toHaveCount($enabled ? 3 : 0)
            ->and($snapshot['panels'])->toHaveCount(3);
        if ($enabled) {
            $surfaces = Scenario::object($snapshot['surfaces']);
            $edufy = Scenario::object($surfaces['edufy.community/edufy.class-feed']);
            expect($edufy['features'])->toBe(['posts', 'reactions'])->and($edufy['contourLevel'])->toBe('class');
        }
    } finally {
        $files->deleteDirectory($root);
    }
})->with([false, true]);
