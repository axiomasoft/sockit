<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

/** Explicit consumer opt-in: offering this module never enables it. */
final class GroupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add($this->app, GroupsModule::class);
        $this->app->scoped(GroupTarget::class);
    }
}
