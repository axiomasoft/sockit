<?php

declare(strict_types=1);

namespace SocKit;

use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use SocKit\Internal\Console\DiagnoseCommand;
use SocKit\Internal\Console\DoctorCommand;
use SocKit\Internal\Console\MakePanelCommand;
use SocKit\Internal\Console\OutboxRunCommand;
use SocKit\Internal\Console\PanelMigrationsCommand;
use SocKit\Internal\Console\RelayCommand;
use SocKit\Internal\RuntimeRegistrar;
use SocKit\Modules\Modules;
use SocKit\Panels\PanelDefinitions;
use SocKit\Plugins\SockitCorePlugin;
use SocKit\Surfaces\Surfaces;

#[Api(since: '1.0')]
class SocKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->getProvider(self::class) !== null) {
            return;
        }

        $this->app->register(PluginsServiceProvider::class);
        ExtensionRegistrar::builtInFor($this->app, SockitCorePlugin::class);
        Surfaces::configure($this->app);
        PanelDefinitions::configure($this->app);
        Modules::configure($this->app);
        if ($this->app->isBooted()) {
            $this->activate();
        } else {
            $this->app->booting($this->activate(...));
        }
    }

    private function activate(): void
    {
        $app = $this->app;
        if ($app->make(PluginInspector::class)->facetsOf('axiomasoft.sockit.core') === null) {
            return;
        }

        new RuntimeRegistrar()->register($app);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()
            || $this->app->getProvider(self::class) !== $this
            || $this->app->make(PluginInspector::class)->facetsOf('axiomasoft.sockit.core') === null) {
            return;
        }

        $this->app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
            $event->artisan->resolveCommands([DiagnoseCommand::class, OutboxRunCommand::class, DoctorCommand::class, MakePanelCommand::class, PanelMigrationsCommand::class, RelayCommand::class]);
        });
        $this->publishes(paths: [__DIR__.'/../config/sockit.php' => $this->app->configPath('sockit.php')], groups: 'sockit-config');
    }
}
