<?php

declare(strict_types=1);

namespace Acme\Groups;

use Acme\Runtime\RuntimeModule;
use Illuminate\Support\ServiceProvider;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\GroupsServiceProvider;
use SocKit\Modules\Modules;
use SocKit\Operations\Contracts\MutationAuthorityFence;

final class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(\Acme\Social\Provider::class);
        $this->app->register(GroupsServiceProvider::class);
        $this->app->bind(MembershipConsent::class, Consent::class);
        if ($this->app->make('config')->get('groups.content', false) === true) {
            $this->app->bind(MutationAuthorityFence::class, Authority::class);
        }
        if ($this->app->make('config')->get('groups.consumer', false) === true) {
            Modules::add($this->app, RuntimeModule::class);
        }
    }
}
