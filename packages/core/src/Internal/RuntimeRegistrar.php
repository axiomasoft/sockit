<?php

declare(strict_types=1);

namespace SocKit\Internal;

use Illuminate\Contracts\Foundation\Application;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Plugins\Contracts\Contributions\Extensions;
use SocKit\Actors\ActorContexts;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Contracts\Module;
use SocKit\Modules\Modules;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Operations\OutboxRelay;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Surfaces\SurfaceInstances;
use SocKit\Surfaces\Surfaces;

final class RuntimeRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $this->registerResolvers($app);
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

    private function registerResolvers(Application $app): void
    {
        SocKitConfig::fromRepository($app->make('config'));
        $app->make('config')->set(key: 'sockit', value: SocKitConfig::normalize($app->make('config')));
        foreach (SocKitConfig::resolverContracts() as $key => $contract) {
            $app->scopedIf(abstract: $contract, concrete: static function (Application $app) use ($key, $contract): object {
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
    }

    public function boot(Application $app): void {}
}
