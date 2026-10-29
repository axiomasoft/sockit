<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Modules;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

final class DemoProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::module(app: $this->app, module: DemoModule::class);
    }
}
