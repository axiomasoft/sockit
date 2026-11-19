<?php

declare(strict_types=1);

namespace SocKit;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use SocKit\Actors\ActorContexts;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Configuration\SocKitConfig;
use SocKit\Errors\SockitProblemDefinitions;
use SocKit\Internal\Console\DoctorCommand;
use SocKit\Internal\Console\MakePanelCommand;
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
        $this->app->register(PluginsServiceProvider::class);
        ExtensionRegistrar::builtInFor($this->app, SockitCorePlugin::class);
        ProblemDefinitions::register($this->app, dirname(__DIR__).'/composer.json', SockitProblemDefinitions::class);
        new ResolverRegistrar()->register($this->app);
        $this->app->scoped(ActorContexts::class);
        OperationHandles::configure($this->app);
        $this->app->scoped(OutboxRelay::class);
        $this->app->scoped(Operations::class);
        $this->app->scoped(SurfaceInstances::class);
        $this->app->scoped(AudienceEvaluator::class);
        $this->app->scoped(AudienceGate::class);
        Surfaces::configure($this->app);
        PanelDefinitions::configure($this->app);
        Modules::configure($this->app);
        $app = $this->app;
        $app->booting(static function () use ($app): void {
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
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class, MakePanelCommand::class, PanelMigrationsCommand::class, RelayCommand::class]);
            $this->publishes(paths: [__DIR__.'/../config/sockit.php' => config_path('sockit.php')], groups: 'sockit-config');
        }
    }
}
