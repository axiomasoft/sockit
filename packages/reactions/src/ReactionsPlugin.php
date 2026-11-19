<?php

declare(strict_types=1);

namespace SocKit\Reactions;

use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use SocKit\Modules\Contracts\Module;
use SocKit\Reactions\Internal\ReactionsModule;

#[Api]
final class ReactionsPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'axiomasoft.sockit.reactions', version: '0.1.0', requires: ['axiomasoft.sockit.core'], resourceRoot: dirname(__DIR__), databases: ['pgsql']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contributeSlot(Module::class, 'sockit.reactions', ReactionsModule::class);
    }
}
