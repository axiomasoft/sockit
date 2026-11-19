<?php

declare(strict_types=1);

namespace SocKit\Internal;

use Illuminate\Contracts\Foundation\Application;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Foundation\Contracts\PackageRegistrar;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Configuration\SockitConfigSection;

final class ResolverRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        SocKitConfig::fromRepository($app->make('config'));
        $app->make('config')->set(key: 'sockit', value: SocKitConfig::normalize($app->make('config')));
        $app->singleton(abstract: SocKitConfig::class, concrete: fn (Application $app): SocKitConfig => SocKitConfig::fromRepository($app->make('config')));
        ConfigSectionRegistration::register($app, SockitConfigSection::class);
        foreach (SocKitConfig::resolverContracts() as $key => $contract) {
            $app->scoped(abstract: $contract, concrete: static function (Application $app) use ($key, $contract): object {
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
