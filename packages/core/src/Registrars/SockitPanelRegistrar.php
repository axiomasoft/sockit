<?php

declare(strict_types=1);

namespace SocKit\Registrars;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use SocKit\Configuration\SockitOptions;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Modules\Groups\Configuration\GroupsSettingsSchema;
use SocKit\Modules\Posts\Configuration\PostsSettingsSchema;
use SocKit\Modules\Reactions\Configuration\ReactionsSettingsSchema;
use SocKit\Panels\Internal\SockitPanelRegistry;
use SocKit\Panels\PanelProvider;
use SocKit\Surfaces\Internal\SurfaceRegistry;
use SocKit\Surfaces\SurfaceProvider;
use WeakMap;

/**
 * Compiles and freezes panels and surfaces atomically once the Laravel application finishes booting.
 */
#[Internal]
final class SockitPanelRegistrar implements PackageRegistrar
{
    /** @var array<string, array{module: string, schema: class-string<ModuleSettingsSchema>}> */
    private const array PLUGIN_MODULES = [
        'axiomasoft.sockit.reactions' => ['module' => 'reactions', 'schema' => ReactionsSettingsSchema::class],
        'axiomasoft.sockit.posts' => ['module' => 'posts', 'schema' => PostsSettingsSchema::class],
        'axiomasoft.sockit.groups' => ['module' => 'groups', 'schema' => GroupsSettingsSchema::class],
    ];

    #[Override]
    public function register(Application $app): void {}

    #[Override]
    public function boot(Application $app): void
    {
        $app->booted(static function () use ($app): void {
            self::compileAndFreezeOnce($app);
        });
    }

    private static function compileAndFreezeOnce(Application $app): void
    {
        /** @var WeakMap<Application, true>|null $compiled */
        static $compiled = null;

        if (! $compiled instanceof WeakMap) {
            /** @var WeakMap<Application, true> $map */
            $map = new WeakMap;
            $compiled = $map;
        }

        if ($compiled->offsetExists($app)) {
            return;
        }
        $compiled[$app] = true;

        $inspector = $app->make(PluginInspector::class);
        $activeModules = [];
        $moduleSchemas = [];

        foreach ($inspector->describe() as $plugin) {
            if (isset(self::PLUGIN_MODULES[$plugin->id])) {
                $entry = self::PLUGIN_MODULES[$plugin->id];
                $activeModules[] = $entry['module'];
                $schemaClass = $entry['schema'];
                $moduleSchemas[$entry['module']] = new $schemaClass;
            }
        }

        $options = $app->make(SockitOptions::class);
        $config = $app->make(Repository::class);
        $rawConnections = $config->get('database.connections', []);
        /** @var array<string, array<string, mixed>> $connections */
        $connections = is_array($rawConnections) ? $rawConnections : [];
        $rawDefault = $config->get('database.default', 'sqlite');
        $defaultConnection = is_string($rawDefault) ? $rawDefault : 'sqlite';

        $panelRegistry = $app->make(SockitPanelRegistry::class);
        $panelRegistry->compileAndFreeze(
            providers: $options->panelProviders,
            options: $options,
            activeModules: $activeModules,
            moduleSchemas: $moduleSchemas,
            databaseConnections: $connections,
            defaultConnection: $defaultConnection,
            providerResolver: static function (string $class) use ($app): PanelProvider {
                /** @var PanelProvider */
                return $app->make($class);
            },
        );

        $surfaceRegistry = $app->make(SurfaceRegistry::class);
        $surfaceRegistry->compileAndFreeze(
            providers: $options->surfaceProviders,
            panels: $panelRegistry,
            providerResolver: static function (string $class) use ($app): SurfaceProvider {
                /** @var SurfaceProvider */
                return $app->make($class);
            },
        );
    }
}
