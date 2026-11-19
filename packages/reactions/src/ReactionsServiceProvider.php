<?php

declare(strict_types=1);

namespace SocKit\Reactions;

use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use SocKit\Modules\Modules;
use SocKit\Reactions\Internal\RebuildCommand;

/** Explicit consumer opt-in: offering this module never enables it. */
#[Api]
final class ReactionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()
            || $this->app->getProvider(self::class) !== $this
            || $this->app->make(PluginInspector::class)->facetsOf('axiomasoft.sockit.reactions') === null
            || ! isset($this->app->make(Modules::class)->manifests()['sockit.reactions'])) {
            return;
        }

        $this->app->make(Dispatcher::class)->listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
            $event->artisan->resolve(RebuildCommand::class);
        });
    }

    public function register(): void
    {
        ExtensionRegistrar::builtInFor($this->app, ReactionsPlugin::class);
    }
}
