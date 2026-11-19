<?php

declare(strict_types=1);

namespace SocKit\Registrars;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use SocKit\Configuration\Internal\SockitModules;
use SocKit\Modules\Groups\GroupsPlugin;
use SocKit\Modules\Posts\PostsPlugin;
use SocKit\Modules\Reactions\ReactionsPlugin;
use SocKit\Plugins\SockitCorePlugin;
use WeakMap;

/**
 * Registers `SockitCorePlugin` and only the optional code module plugins enabled via `sockit.modules.enabled`.
 */
#[Internal]
final class SockitPluginRegistrar implements PackageRegistrar
{
    /** @var array<string, class-string<ExtensionPlugin>> */
    private const array MODULE_PLUGINS = [
        'reactions' => ReactionsPlugin::class,
        'posts' => PostsPlugin::class,
        'groups' => GroupsPlugin::class,
    ];

    #[Override]
    public function register(Application $app): void
    {
        ExtensionRegistrar::builtInFor($app, SockitCorePlugin::class);

        $app->beforeResolving(PluginInspector::class, static function (string $abstract, array $parameters, mixed $container): void {
            if ($container instanceof Application) {
                self::syncConfiguredModulePlugins($container);
            }
        });
    }

    #[Override]
    public function boot(Application $app): void
    {
        self::syncConfiguredModulePlugins($app);
    }

    private static function syncConfiguredModulePlugins(Application $app): void
    {
        /** @var WeakMap<Application, true>|null $synced */
        static $synced = null;

        if (! $synced instanceof WeakMap) {
            /** @var WeakMap<Application, true> $map */
            $map = new WeakMap;
            $synced = $map;
        }

        if ($synced->offsetExists($app)) {
            return;
        }
        $synced[$app] = true;

        $plugins = [SockitCorePlugin::class];

        if ($app->bound(Repository::class)) {
            $rawEnabled = $app->make(Repository::class)->get('sockit.modules.enabled', []);

            if (is_array($rawEnabled)) {
                $moduleList = [];
                foreach ($rawEnabled as $entry) {
                    if (is_string($entry) && $entry !== '') {
                        $moduleList[] = $entry;
                    }
                }

                foreach (SockitModules::normalizeList($moduleList) as $canonical) {
                    if (isset(self::MODULE_PLUGINS[$canonical])) {
                        $plugins[] = self::MODULE_PLUGINS[$canonical];
                    }
                }
            }
        }

        ExtensionRegistrar::builtInFor($app, ...$plugins);
    }
}
