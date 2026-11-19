<?php

declare(strict_types=1);

namespace SocKit\Plugins;

use Packman\Attributes\Api;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;
use SocKit\Configuration\SocKitConfig;
use SocKit\Configuration\SockitConfigSection;
use SocKit\Errors\SockitProblemDefinitions;
use SocKit\Modules\Contracts\Module;

#[Api]
final class SockitCorePlugin implements ExtensionPlugin, WithConfiguration, WithDiagnostics
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'axiomasoft.sockit.core', version: '0.1.0', databases: ['pgsql'], resourceRoot: dirname(__DIR__, 2));
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineSlot(Module::class, SlotKind::Contributor);
        foreach (SocKitConfig::resolverContracts() as $key => $contract) {
            $registrar->defineCapability($contract, Cardinality::OptionalSingle)
                ->providesFromConfig($contract, 'sockit.resolvers.'.$key);
        }
    }

    public function configRoot(): string
    {
        return 'sockit';
    }

    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/sockit.php';
    }

    public function optionsClass(): string
    {
        return SocKitConfig::class;
    }

    public function configSectionClass(): string
    {
        return SockitConfigSection::class;
    }

    public function healthChecks(): array
    {
        return [];
    }

    public function problemProviders(): array
    {
        return [SockitProblemDefinitions::class];
    }
}
