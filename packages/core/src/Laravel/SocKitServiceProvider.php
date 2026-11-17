<?php

declare(strict_types=1);

namespace SocKit\Laravel;

use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;
use SocKit\Registrars\SockitCoreRegistrar;
use SocKit\Registrars\SockitPanelRegistrar;
use SocKit\Registrars\SockitPluginRegistrar;

/**
 * Thin Laravel service provider composing SocKit's plugin, core service, and panel registrars.
 */
#[Api(since: '1.0')]
final class SocKitServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars;

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        SockitPluginRegistrar::class,
        SockitCoreRegistrar::class,
        SockitPanelRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/sockit.php', 'sockit');
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        $this->bootPackageRegistrars();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/sockit.php' => $this->app->configPath('sockit.php'),
            ], 'sockit-config');
        }
    }
}
