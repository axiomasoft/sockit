<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Tests\Fixtures\Runtime\SqlInboxConsumer;
use Symfony\Component\Process\Process;

it('normalizes server event generation with scalar-only bounded relay config', function (): void {
    $defaults = SocKitConfig::fromRepository(new Repository);
    expect($defaults->events->sourceGeneration)->toBe('1')->and($defaults->events->leaseSeconds)->toBe(30);
    $repository = new Repository(['sockit' => ['events' => ['source_generation' => 'generation-2']]]);
    $config = SocKitConfig::fromRepository($repository);
    $repository->set(key: 'sockit.events.source_generation', value: '3');
    expect($config->events->sourceGeneration)->toBe('generation-2')->and($config->events->maxAttempts)->toBe(10);
});

it('rejects malformed event settings at boot', function (string $key, mixed $value): void {
    expect(fn () => SocKitConfig::fromRepository(new Repository(['sockit' => ['events' => [$key => $value]]])))->toThrow(InvalidConfigurationException::class);
})->with([
    ['source_generation', ''], ['source_generation', str_repeat('x', 65)], ['source_generation', "x\ny"], ['source_generation', 1], ['source_generation', null],
    ['source_generation', ['client' => 'untrusted']], ['lease_seconds', 0], ['lease_seconds', '30'], ['lease_seconds', 3601], ['retry_seconds', 0], ['max_attempts', 0], ['unknown', 1],
]);

it('caches actual Artisan config with consumer FQCNs and event generation then bootstraps a fresh process without SQL', function (): void {
    $root = sys_get_temp_dir().'/sockit-p26-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    foreach (['bootstrap/cache', 'config', 'storage/framework', 'storage/logs'] as $directory) {
        $files->makeDirectory(path: $root.'/'.$directory, mode: 0700, recursive: true);
    }
    $files->put(path: $root.'/bootstrap/app.php', contents: '<?php return \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__."/..")->withProviders([\\SocKit\\SocKitServiceProvider::class, \\SocKit\\Tests\\Fixtures\\Runtime\\OutboxProvider::class])->create();');
    $files->put(path: $root.'/config/sockit.php', contents: '<?php return '.var_export(['events' => ['source_generation' => 'frozen-server-2'], 'modules' => ['enabled' => ['acme.runtime']]], true).';');
    $run = static function (string $command) use ($root): string {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Runtime/outbox-console.php', $root, $command], env: ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/config.php']);
        $process->mustRun();

        return $process->getOutput();
    };
    try {
        $before = $run('snapshot');
        $run('config:cache');
        expect(is_file($root.'/bootstrap/cache/config.php'))->toBeTrue()->and($run('snapshot'))->toBe($before);
        $snapshot = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        expect(data_get($snapshot, 'events.sourceGeneration'))->toBe('frozen-server-2')->and(data_get($snapshot, 'connections'))->toBe(0)
            ->and(data_get($snapshot, ['modules', 'acme.runtime', 'consumers', 'acme.inbox']))->toBe(SqlInboxConsumer::class);
    } finally {
        $files->deleteDirectory($root);
    }
});
