<?php

declare(strict_types=1);

namespace Acme\Groups;

use Acme\Runtime\RuntimeModule;
use Illuminate\Support\ServiceProvider;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Internal\GroupsServiceProvider;
use SocKit\Modules\Modules;

final class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(\Acme\Social\Provider::class);
        $this->app->register(GroupsServiceProvider::class);
        $this->app->bind(MembershipConsent::class, Consent::class);
        if ($this->app->make('config')->get('groups.consumer', false) === true) {
            Modules::add($this->app, RuntimeModule::class);
        }
    }
}
