<?php

declare(strict_types=1);

namespace SocKit\Reactions;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Plugins\Laravel\ExtensionRegistrar;

/** Explicit consumer opt-in: offering this module never enables it. */
#[Api]
final class ReactionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ExtensionRegistrar::builtInFor($this->app, ReactionsPlugin::class);
    }
}
