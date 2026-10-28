<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Modules;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

final class OtherProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add(app: $this->app, modules: OtherModule::class);
    }
}
