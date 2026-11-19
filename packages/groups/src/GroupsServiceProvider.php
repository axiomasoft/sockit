<?php

declare(strict_types=1);

namespace SocKit\Groups;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use SocKit\Groups\Internal\GroupTarget;

/** Explicit consumer opt-in: offering this module never enables it. */
#[Api]
final class GroupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ExtensionRegistrar::builtInFor($this->app, GroupsPlugin::class);
        $this->app->scoped(GroupTarget::class);
    }
}
