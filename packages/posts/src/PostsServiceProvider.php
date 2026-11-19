<?php

declare(strict_types=1);

namespace SocKit\Posts;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use SocKit\Posts\Internal\PostTarget;

/** Explicit consumer opt-in: offering this module never enables it. */
#[Api]
final class PostsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ExtensionRegistrar::builtInFor($this->app, PostsPlugin::class);
    }

    public function boot(): void
    {
        if ($this->app->make(PluginInspector::class)->facetsOf('axiomasoft.sockit.posts') !== null) {
            $this->app->scopedIf(PostTarget::class);
        }
    }
}
