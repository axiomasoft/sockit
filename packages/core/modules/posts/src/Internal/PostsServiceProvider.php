<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

/** Explicit consumer opt-in: offering this module never enables it. */
final class PostsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add($this->app, PostsModule::class);
    }
}
