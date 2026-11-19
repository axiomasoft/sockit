<?php

declare(strict_types=1);

namespace SocKit\Groups;

use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use SocKit\Groups\Internal\GroupsModule;
use SocKit\Modules\Contracts\Module;

#[Api]
final class GroupsPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'axiomasoft.sockit.groups', version: '0.1.0', requires: ['axiomasoft.sockit.core'], resourceRoot: dirname(__DIR__), databases: ['pgsql']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contributeSlot(Module::class, 'sockit.groups', GroupsModule::class);
    }
}
