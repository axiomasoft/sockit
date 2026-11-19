<?php

declare(strict_types=1);

namespace SocKit\Posts;

use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use SocKit\Modules\Contracts\Module;
use SocKit\Posts\Internal\PostsModule;

#[Api]
final class PostsPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'axiomasoft.sockit.posts', version: '0.1.0', requires: ['axiomasoft.sockit.core'], resourceRoot: dirname(__DIR__), databases: ['pgsql']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contributeSlot(Module::class, 'sockit.posts', PostsModule::class);
    }
}
