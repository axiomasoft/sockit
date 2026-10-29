<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

final class SchemaProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add($this->app, DemoTargetsModule::class, DependentModule::class);
    }
}
