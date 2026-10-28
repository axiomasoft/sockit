<?php

declare(strict_types=1);

namespace SocKit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SocKit\Actors\ActorContexts;
use SocKit\Attributes\Api;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Modules;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Surfaces;

#[Api(since: '1.0')]
class SocKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        SocKitConfig::fromRepository($this->app->make('config'));
        $this->app->make('config')->set(key: 'sockit', value: SocKitConfig::normalize($this->app->make('config')));
        $this->app->singleton(abstract: SocKitConfig::class, concrete: fn (Application $app): SocKitConfig => SocKitConfig::fromRepository($app->make('config')));
        foreach (SocKitConfig::resolverContracts() as $key => $contract) {
            if ($key === 'targets') {
                continue; // TargetResolver is introduced with its runtime consumer in P2.
            }
            $this->app->scoped(abstract: $contract, concrete: static function (Application $app) use ($key, $contract): object {
                $resolvers = $app->make(SocKitConfig::class)->resolvers;
                $fqcn = $resolvers->for($key);
                if ($fqcn === null) {
                    throw new ProviderUnavailableException(message: 'Required SocKit resolver is unavailable', detail: ['key' => 'sockit.resolvers.'.$key, 'rule' => 'PROVIDER_REQUIRED']);
                }
                $instance = $app->make($fqcn);
                if (! is_object($instance) || ! is_a($instance, $contract)) {
                    throw new ProviderUnavailableException(message: 'Configured resolver binding does not implement its contract', detail: ['key' => 'sockit.resolvers.'.$key, 'rule' => 'PROVIDER_IMPLEMENTATION']);
                }

                return $instance;
            });
        }
        $this->app->scoped(ActorContexts::class);
        OperationHandles::configure($this->app);
        $this->app->scoped(AudienceEvaluator::class);
        $this->app->scoped(AudienceGate::class);
        Surfaces::configure($this->app);
        PanelDefinitions::configure($this->app);
        Modules::configure($this->app);
        $app = $this->app;
        $app->booting(static function () use ($app): void {
            $draft = Modules::compile($app);
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
            $this->publishes(paths: [__DIR__.'/../config/sockit.php' => config_path('sockit.php')], groups: 'sockit-config');
        }
    }
}
