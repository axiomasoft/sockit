<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

final class SocialServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add($this->app, PostsModule::class, ReactionsModule::class, SurfaceModule::class);
    }
}
