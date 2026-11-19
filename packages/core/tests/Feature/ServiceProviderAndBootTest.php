<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Packman\Config\Contracts\SettingsLookup;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use SocKit\Configuration\SockitOptions;
use SocKit\Configuration\SockitSectionSettings;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\BetaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Core\Tests\Fixtures\PanelBetaProvider;
use SocKit\Modules\Posts\Configuration\PostsSettingsSchema;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\FrozenDefinitionException;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Panels\Internal\SockitPanelRegistry;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\SockitPanelBuilder;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Exceptions\SurfaceException;
use SocKit\Surfaces\Internal\SurfaceRegistry;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

test('the registered options reject a malformed host configuration root', function (): void {
    $this->app['config']->set('sockit', 'invalid');
    $this->app->forgetInstance(SockitOptions::class);

    expect(fn (): SockitOptions => $this->app->make(SockitOptions::class))
        ->toThrow(InvalidConfiguration::class, 'sockit: expected an array');
});

test('SockitConfigSection binds SettingsLookup and SockitOptions roundtrips through real Laravel config:cache', function (): void {
    $appLookup = $this->app->make(SettingsLookup::class);
    $appSection = $this->app->make(SockitSectionSettings::class);
    expect($appLookup->get(SockitSectionSettings::class))->toBe($appSection)
        ->and($appSection->options)->toEqual($this->app->make(SockitOptions::class));

    $tmpBase = sys_get_temp_dir().'/sockit-config-cache-'.bin2hex(random_bytes(6));
    $previousContainer = Container::getInstance();
    $previousFacade = Facade::getFacadeApplication();

    $removeTree = static function (string $path) use (&$removeTree): void {
        if (! file_exists($path)) {
            return;
        }
        if (is_dir($path) && ! is_link($path)) {
            $entries = scandir($path);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $removeTree($path.'/'.$entry);
                }
            }
            rmdir($path);

            return;
        }
        unlink($path);
    };

    try {
        foreach (['bootstrap/cache', 'config', 'storage/framework/cache', 'storage/logs'] as $dir) {
            mkdir($tmpBase.'/'.$dir, 0775, true);
        }

        $bootstrapApp = <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Packman\PackmanServiceProvider;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use SocKit\Laravel\SocKitServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        PackmanServiceProvider::class,
        SchemaServiceProvider::class,
        PluginsServiceProvider::class,
        PanelsServiceProvider::class,
        SocKitServiceProvider::class,
    ])
    ->create();
PHP;
        file_put_contents($tmpBase.'/bootstrap/app.php', $bootstrapApp);
        file_put_contents($tmpBase.'/config/app.php', "<?php\nreturn ['name' => 'SockitBootTest', 'env' => 'testing', 'debug' => false, 'timezone' => 'UTC'];\n");
        file_put_contents($tmpBase.'/config/database.php', "<?php\nreturn ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]];\n");
        file_put_contents(
            $tmpBase.'/config/sockit.php',
            <<<'PHP'
<?php

use SocKit\Actors\SingleInstallationTenantResolver;
use SocKit\Audience\AllowAuthenticatedPersonAuthorizer;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\BetaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Core\Tests\Fixtures\PanelBetaProvider;
use SocKit\Testing\Fakes\Actors\FakeActorContextResolver;
use SocKit\Testing\Fakes\Audience\FakeSurfaceAudiencePolicy;

return [
    'ids' => ['strategy' => 'uuid7'],
    'storage' => ['connection' => 'sqlite', 'table_prefix' => 'sk_'],
    'panels' => [PanelAlphaProvider::class, PanelBetaProvider::class],
    'surfaces' => [AlphaWallSurfaceProvider::class, BetaWallSurfaceProvider::class],
    'modules' => [
        'enabled' => ['reactions', 'posts', 'groups'],
        'settings' => [
            'posts' => ['max_body_bytes' => 4096],
        ],
    ],
    'privacy' => [
        'floor' => [
            'allowed_scopes' => ['direct', 'group', 'contour', 'platform'],
            'default_scope' => 'platform',
            'max_scope' => 'platform',
            'minor_default_scope' => 'direct',
            'minor_max_scope' => 'group',
            'contour_levels' => ['team'],
        ],
    ],
    'resolvers' => [
        'tenant' => SingleInstallationTenantResolver::class,
        'actor' => FakeActorContextResolver::class,
        'authorizer' => AllowAuthenticatedPersonAuthorizer::class,
        'surface_policy' => FakeSurfaceAudiencePolicy::class,
    ],
];
PHP,
        );

        /** @var Application $app1 */
        $app1 = require $tmpBase.'/bootstrap/app.php';
        $app1->useStoragePath($tmpBase.'/storage');
        $exitCode = $app1->make(ConsoleKernelContract::class)->call('config:cache');

        expect($exitCode)->toBe(0)
            ->and(is_file($tmpBase.'/bootstrap/cache/config.php'))->toBeTrue();

        /** @var Application $app2 */
        $app2 = require $tmpBase.'/bootstrap/app.php';
        $app2->useStoragePath($tmpBase.'/storage');
        $app2->make(ConsoleKernelContract::class)->bootstrap();

        expect($app2->configurationIsCached())->toBeTrue();

        $options = $app2->make(SockitOptions::class);
        $settings = $app2->make(SettingsLookup::class)->get(SockitSectionSettings::class);
        $panels = $app2->make(PanelDefinitions::class);
        $surfaces = $app2->make(SurfaceDefinitions::class);
        $inspector = $app2->make(PluginInspector::class);

        expect($options->idStrategy)->toBe('uuid7')
            ->and($options->tablePrefix->value())->toBe('sk_')
            ->and($settings->options->moduleSettings['posts']['max_body_bytes'] ?? null)->toBe(4096)
            ->and($panels->get(new PanelId('sockit.alpha'))->moduleSettings['posts']['max_body_bytes'] ?? null)->toBe(4096)
            ->and($surfaces->get(new PanelId('sockit.beta'), new SurfaceKindId('sockit.wall'))->label)->toBe('Beta Wall')
            ->and($inspector->describe())->not->toBeEmpty();
    } finally {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($previousFacade);
        Container::setInstance($previousContainer);
        $removeTree($tmpBase);
    }
});

test('panel and surface provider compilation is strictly deterministic across registration permutations', function (): void {
    $options = SockitOptions::fromArray([
        'privacy' => [
            'floor' => [
                'allowed_scopes' => ['direct', 'group', 'contour', 'platform'],
                'default_scope' => 'platform',
                'max_scope' => 'platform',
                'minor_default_scope' => 'direct',
                'minor_max_scope' => 'group',
                'contour_levels' => ['team'],
            ],
        ],
    ]);
    $schemas = ['posts' => new PostsSettingsSchema];
    $connections = ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']];

    $regA = new SockitPanelRegistry;
    $regA->compileAndFreeze(
        providers: [PanelAlphaProvider::class, PanelBetaProvider::class],
        options: $options,
        activeModules: ['reactions', 'posts', 'groups'],
        moduleSchemas: $schemas,
        databaseConnections: $connections,
        defaultConnection: 'sqlite',
        providerResolver: static fn (string $cls): PanelProvider => new $cls,
    );

    $regB = new SockitPanelRegistry;
    $regB->compileAndFreeze(
        providers: [PanelBetaProvider::class, PanelAlphaProvider::class],
        options: $options,
        activeModules: ['groups', 'posts', 'reactions'],
        moduleSchemas: $schemas,
        databaseConnections: $connections,
        defaultConnection: 'sqlite',
        providerResolver: static fn (string $cls): PanelProvider => new $cls,
    );

    expect(array_keys($regA->all()))->toBe(['sockit.alpha', 'sockit.beta'])
        ->and(array_keys($regB->all()))->toBe(['sockit.alpha', 'sockit.beta'])
        ->and($regA->all())->toEqual($regB->all());

    $surfA = new SurfaceRegistry;
    $surfA->compileAndFreeze(
        providers: [AlphaWallSurfaceProvider::class, BetaWallSurfaceProvider::class],
        panels: $regA,
        providerResolver: static fn (string $cls): SurfaceProvider => new $cls,
    );

    $surfB = new SurfaceRegistry;
    $surfB->compileAndFreeze(
        providers: [BetaWallSurfaceProvider::class, AlphaWallSurfaceProvider::class],
        panels: $regB,
        providerResolver: static fn (string $cls): SurfaceProvider => new $cls,
    );

    expect(array_keys($surfA->all()))->toBe(['sockit.alpha/sockit.wall', 'sockit.beta/sockit.wall'])
        ->and(array_keys($surfB->all()))->toBe(['sockit.alpha/sockit.wall', 'sockit.beta/sockit.wall'])
        ->and($surfA->get(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'))->label)->toBe('Alpha Wall');
});

test('panel and surface registries reject early reads before freeze and late mutations after freeze', function (): void {
    $panels = new SockitPanelRegistry;
    $surfaces = new SurfaceRegistry;

    expect(fn (): array => $panels->all())->toThrow(FrozenDefinitionException::class);
    expect(fn (): array => $surfaces->all())->toThrow(FrozenDefinitionException::class);

    $panels->compileAndFreeze([], SockitOptions::fromArray([]), [], [], [], 'sqlite', static fn (string $c): PanelProvider => new $c);
    $surfaces->compileAndFreeze([], $panels, static fn (string $c): SurfaceProvider => new $c);

    expect(fn () => $panels->compileAndFreeze([], SockitOptions::fromArray([]), [], [], [], 'sqlite', static fn (string $c): PanelProvider => new $c))
        ->toThrow(FrozenDefinitionException::class);
    expect(fn () => $surfaces->compileAndFreeze([], $panels, static fn (string $c): SurfaceProvider => new $c))
        ->toThrow(FrozenDefinitionException::class);
});

test('invalid unused panel and surface declarations fail closed at compile time', function (): void {
    $options = SockitOptions::fromArray([]);
    $connections = ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']];

    $inactiveModulePanel = new class extends PanelProvider
    {
        public function panel(SockitPanelBuilder $panel): SockitPanelBuilder
        {
            return $panel->id('sockit.invalid')->hostTypes('board')->modules('posts');
        }
    };

    $reg = new SockitPanelRegistry;
    expect(fn () => $reg->compileAndFreeze(
        [$inactiveModulePanel],
        $options,
        ['reactions'],
        [],
        $connections,
        'sqlite',
        static fn (string $c): PanelProvider => new $c,
    ))->toThrow(PanelException::class);

    $validPanel = new class extends PanelProvider
    {
        public function panel(SockitPanelBuilder $panel): SockitPanelBuilder
        {
            return $panel->id('sockit.only-reactions')->hostTypes('board')->modules('reactions');
        }
    };

    $reg2 = new SockitPanelRegistry;
    $reg2->compileAndFreeze([$validPanel], $options, ['reactions'], [], $connections, 'sqlite', static fn (string $c): PanelProvider => new $c);

    $badHostSurface = new class extends SurfaceProvider
    {
        public function surface(Surface $surface): Surface
        {
            return $surface->panel(new PanelId('sockit.only-reactions'))->kind('sockit.wall')->host('article');
        }
    };

    $surfReg = new SurfaceRegistry;
    expect(fn () => $surfReg->compileAndFreeze([$badHostSurface], $reg2, static fn (string $c): SurfaceProvider => new $c))
        ->toThrow(SurfaceException::class);

    $unsupportedFeatureSurface = new class extends SurfaceProvider
    {
        public function surface(Surface $surface): Surface
        {
            return $surface->panel(new PanelId('sockit.only-reactions'))->kind('sockit.wall')->host('board')->features('posts');
        }
    };

    $surfReg2 = new SurfaceRegistry;
    expect(fn () => $surfReg2->compileAndFreeze([$unsupportedFeatureSurface], $reg2, static fn (string $c): SurfaceProvider => new $c))
        ->toThrow(PanelException::class);
});
