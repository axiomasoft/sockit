<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;

final class OutboxProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add(app: $this->app, modules: OutboxModule::class);
    }
}
