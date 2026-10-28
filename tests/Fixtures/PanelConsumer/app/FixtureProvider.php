<?php

declare(strict_types=1);

namespace Acme;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

final class FixtureProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add(app: $this->app, modules: DemoModule::class);
    }
}
