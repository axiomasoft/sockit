<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

final class TargetProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add(app: $this->app, modules: TargetModule::class);
        Modules::add(app: $this->app, modules: OtherTargetModule::class);
    }
}
