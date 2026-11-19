<?php

declare(strict_types=1);

namespace SocKit\Reactions;

use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use SocKit\Reactions\Internal\RebuildCommand;

/** Explicit consumer opt-in: offering this module never enables it. */
#[Api]
final class ReactionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RebuildCommand::class]);
        }
    }

    public function register(): void
    {
        ExtensionRegistrar::builtInFor($this->app, ReactionsPlugin::class);
    }
}
