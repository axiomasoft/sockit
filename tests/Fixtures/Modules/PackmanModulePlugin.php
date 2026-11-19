<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Modules;

use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use SocKit\Modules\Contracts\Module;

final class PackmanModulePlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'acme.social', version: '1.0.0', requires: ['axiomasoft.sockit.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contributeSlot(Module::class, 'acme.demo', DemoModule::class);
    }
}
