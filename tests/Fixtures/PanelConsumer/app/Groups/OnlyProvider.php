<?php

declare(strict_types=1);

namespace Acme\Groups;

use Illuminate\Support\ServiceProvider;
use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\GroupsServiceProvider;
use SocKit\Operations\Contracts\MutationAuthorityFence;

final class OnlyProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(GroupsServiceProvider::class);
        $this->app->bind(MembershipConsent::class, SubjectConsent::class);
        $this->app->bind(MutationAuthorityFence::class, Authority::class);
    }
}
