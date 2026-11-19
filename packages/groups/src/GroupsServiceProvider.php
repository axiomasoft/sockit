<?php

declare(strict_types=1);

namespace SocKit\Groups;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use SocKit\Groups\Internal\GroupTarget;

/** Explicit consumer opt-in: offering this module never enables it. */
#[Api]
final class GroupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ExtensionRegistrar::builtInFor($this->app, GroupsPlugin::class);
    }

    public function boot(): void
    {
        if ($this->app->make(PluginInspector::class)->facetsOf('axiomasoft.sockit.groups') !== null) {
            $this->app->scopedIf(GroupTarget::class);
        }
    }
}
