<?php

declare(strict_types=1);
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\ModuleRegistrar;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Modules\DemoSettingsSchema;
use SocKit\Tests\Fixtures\Schema\DemoTargetsV2;
use SocKit\Tests\Fixtures\Schema\DependentContribution;
use SocKit\Tests\Fixtures\Schema\InvalidContribution;
use SocKit\Tests\Fixtures\Schema\SchemaPanel;
use SocKit\Tests\Fixtures\Schema\SchemaProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

/** @param array<string, mixed> $fixture
 * @param list<class-string> $providers */
function schemaApp(array $fixture = [], array $providers = [SocKitServiceProvider::class, SchemaProvider::class]): Application
{
    $app = new Application;
    $app->instance('config', new Repository(['sockit' => ['panels' => [SchemaPanel::class], 'modules' => ['enabled' => ['acme.demo-targets', 'acme.demo-links']]], 'schema_fixture' => $fixture]));
    $app->bind('db', static function (): never {
        throw new RuntimeException('Schema compilation must not resolve a database');
    });
    foreach ($providers as $provider) {
        $app->register($provider);
    }

    return $app;
}

it('publishes identical immutable schema-aware maps in either provider order without resolving DB or future classes', function (): void {
    $expected = null;
    $loads = [];
    $loader = static function (string $class) use (&$loads): void {
        if (str_starts_with($class, 'App\\SocKit\\Generated\\')) {
            $loads[] = $class;
        }
    };
    spl_autoload_register($loader);
    try {
        foreach ([[SocKitServiceProvider::class, SchemaProvider::class], [SchemaProvider::class, SocKitServiceProvider::class]] as $order) {
            $app = schemaApp(fixture: ['tables' => ['demo_targets' => 'custom_targets']], providers: $order);
            expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);
            $app->boot();
            $panel = $app->make(PanelDefinitions::class)->get(new PanelId('acme.articles'));
            $modules = $app->make(Modules::class);
            $schema = new PanelSchemaCompiler()->compile(panel: $panel, components: $modules->schemaComponents());
            $expected ??= $schema->toJson();
            expect($schema->toJson())->toBe($expected)
                ->and($panel->storage->tables['demo_targets'])->toBe('articles_custom_targets')
                ->and($panel->storage->models)->toHaveCount(6)
                ->and($modules->settings()['acme.demo-targets'])->toBe(['limit' => 10]);
            expect($schema->jsonSerialize()['mode'])->toBe('draft_generation');
        }
        expect($loads)->toBe([]);
    } finally {
        spl_autoload_unregister($loader);
    }
});

it('retains builder freeze and rejects schema registration after freeze', function (): void {
    $app = schemaApp();
    $instance = new SchemaPanel($app->make('config'));
    $app->instance(SchemaPanel::class, $instance);
    $app->boot();
    expect(fn () => $instance->builder?->prefix('changed_'))->toThrow(InvalidPanelException::class);
    $registrar = new ModuleRegistrar('acme.demo-targets');
    $registrar->schema(DemoTargetsV2::class);
    $registrar->freeze();
    expect(fn () => $registrar->schema(DemoTargetsV2::class))->toThrow(InvalidModuleException::class);
});

it('keeps enabled but unselected schema fragments out of panel maps', function (): void {
    $app = schemaApp(['selected' => []]);
    $app->boot();
    expect($app->make(Modules::class)->schemaComponents())->toHaveCount(2)
        ->and($app->make(PanelDefinitions::class)->get(new PanelId('acme.articles'))->storage->tables)->toHaveCount(4);
});

it('fails before any registry publication for an unused invalid component or missing selected dependency', function (array $fixture, string $rule): void {
    $app = schemaApp(Scenario::object($fixture));
    try {
        $app->boot();
        Assert::fail('Invalid graph booted');
    } catch (InvalidSchemaException $error) {
        expect($error->detail()['rule'])->toBe($rule);
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class);
})->with([
    'unused duplicate kernel entity' => [['selected' => [], 'contribution' => InvalidContribution::class], 'SCHEMA_ENTITY_DUPLICATE'],
    'dependency enabled but not selected' => [['selected' => ['acme.demo-links']], 'SCHEMA_DEPENDENCY_MISSING'],
]);

it('rejects unknown or unselected logical table overrides', function (array $fixture): void {
    expect(fn () => schemaApp(Scenario::object($fixture))->boot())->toThrow(InvalidPanelException::class);
})->with([
    [['tables' => ['unknown' => 'foreign']]],
    [['selected' => [], 'tables' => ['demo_targets' => 'foreign']]],
]);

it('does not execute disabled offered schema registration', function (): void {
    $app = schemaApp(['contribution' => InvalidContribution::class, 'selected' => []]);
    $app->make('config')->set('sockit.modules.enabled', []);
    $app->boot();
    expect($app->make(Modules::class)->schemaComponents())->toBe([]);
});

it('keeps schema contributions separate from settings and rejects duplicate or invalid FQCN slots', function (): void {
    $registrar = new ModuleRegistrar('acme.demo-targets');
    $registrar->settings(DemoSettingsSchema::class)->schema(DemoTargetsV2::class);
    expect($registrar->declarations()['schema'])->toBe(DemoTargetsV2::class)
        ->and($registrar->declarations()['settings'])->toBe(DemoSettingsSchema::class)
        ->and(fn () => $registrar->schema(DemoTargetsV2::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => new ModuleRegistrar('acme.invalid')->schema(DemoSettingsSchema::class))->toThrow(InvalidModuleException::class);
});

it('rebuilds identical schema snapshots in a fresh process after real config cache with zero connections', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/sockit-schema-cache-'.bin2hex(random_bytes(8));
    foreach (['bootstrap/cache', 'config', 'storage/framework', 'storage/logs'] as $directory) {
        $files->makeDirectory(path: $root.'/'.$directory, mode: 0700, recursive: true);
    }
    $files->put(path: $root.'/bootstrap/app.php', contents: <<<'BOOT'
<?php
return \Illuminate\Foundation\Application::configure(basePath: __DIR__.'/..')
    ->withProviders([\SocKit\SocKitServiceProvider::class, \SocKit\Tests\Fixtures\Schema\SchemaProvider::class])->create();
BOOT);
    $files->put(path: $root.'/config/sockit.php', contents: '<?php return '.var_export(['panels' => [SchemaPanel::class], 'modules' => ['enabled' => ['acme.demo-targets', 'acme.demo-links']]], true).';');
    $run = static function (string $command) use ($root): string {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Schema/console.php', $root, $command], env: ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $root.'/bootstrap/cache/config.php']);
        $process->mustRun();

        return $process->getOutput();
    };
    try {
        $before = $run('snapshot');
        $run('config:cache');
        expect(is_file($root.'/bootstrap/cache/config.php'))->toBeTrue();
        $after = $run('snapshot');
        expect($after)->toBe($before);
        $wire = Scenario::object(json_decode($after, true, flags: JSON_THROW_ON_ERROR));
        expect($wire['connections'])->toBe(0)->and($wire['queries'])->toBe(0);
        $snapshot = Scenario::object($wire['schema']);
        expect($snapshot['mode'])->toBe('draft_generation')->and($snapshot['dependency_order'])->toBe(['sockit.kernel', 'acme.demo-targets', 'acme.demo-links']);
    } finally {
        $files->deleteDirectory($root);
    }
});

it('rejects schema container bindings with the wrong implementation or owner before publication', function (bool $wrongOwner): void {
    $app = schemaApp(['selected' => []]);
    $app->instance(DemoTargetsV2::class, $wrongOwner ? new DependentContribution : new stdClass);
    try {
        $app->boot();
        Assert::fail('Invalid schema binding accepted');
    } catch (InvalidSchemaException $error) {
        expect($error->detail()['rule'])->toBe($wrongOwner ? 'SCHEMA_OWNER_INVALID' : 'SCHEMA_IMPLEMENTATION_INVALID');
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class);
})->with([true, false]);
