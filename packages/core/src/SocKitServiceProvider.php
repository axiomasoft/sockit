<?php

declare(strict_types=1);

namespace SocKit;

use Illuminate\Support\ServiceProvider;
use SocKit\Attributes\Api;

#[Api(since: '1.0')]
class SocKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sockit.php', 'sockit');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/sockit.php' => config_path('sockit.php')], 'sockit-config');
    }
}
