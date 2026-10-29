<?php

declare(strict_types=1);

namespace Acme\Social;

use Acme\Runtime\AuthorityRouter;
use Acme\Runtime\RuntimeModule;
use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Posts\Internal\PostsServiceProvider;
use SocKit\Reactions\Internal\ReactionsServiceProvider;

final class Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(ReactionsServiceProvider::class);
        if ($this->app->make('config')->get('social.posts_offered', false) === true) {
            $this->app->register(PostsServiceProvider::class);
            Modules::add($this->app, RuntimeModule::class);
        }
        $this->app->bind(MutationAuthorityFence::class, AuthorityRouter::class);
    }
}
