<?php

declare(strict_types=1);

namespace SocKit;

use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use SocKit\Actors\ActorContexts;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Configuration\SocKitConfig;
use SocKit\Internal\Console\DiagnoseCommand;
use SocKit\Internal\Console\DoctorCommand;
use SocKit\Internal\Console\MakePanelCommand;
use SocKit\Internal\Console\OutboxRunCommand;
use SocKit\Internal\Console\PanelMigrationsCommand;
use SocKit\Internal\Console\RelayCommand;
use SocKit\Internal\ResolverRegistrar;
use SocKit\Modules\Contracts\Module;
use SocKit\Modules\Modules;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Operations\OutboxRelay;
use SocKit\Panels\PanelDefinitions;
use SocKit\Plugins\SockitCorePlugin;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Surfaces\SurfaceInstances;
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

        new ResolverRegistrar()->register($app);
        $app->scopedIf(ActorContexts::class);
        OperationHandles::configure($app);
        $app->scopedIf(OutboxRelay::class);
        $app->scopedIf(Operations::class);
        $app->scopedIf(SurfaceInstances::class);
        $app->scopedIf(AudienceEvaluator::class);
        $app->scopedIf(AudienceGate::class);
        foreach ($app->make(Extensions::class)->contributions(Module::class) as $contribution) {
            Modules::add($app, $contribution->implementation);
        }
        $draft = Modules::compile($app);
        new PanelSchemaCompiler()->validateComponents($draft->schemaComponents());
        $panels = PanelDefinitions::compile(app: $app, config: $app->make(SocKitConfig::class), modules: $draft);
        Surfaces::compile(app: $app, config: $app->make(SocKitConfig::class), modules: $draft, panels: $panels);
        Modules::publish(app: $app, draft: $draft);
        PanelDefinitions::publish(app: $app, draft: $panels);
        Surfaces::publish($app);
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
