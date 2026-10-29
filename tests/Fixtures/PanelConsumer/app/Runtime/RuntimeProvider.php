<?php

declare(strict_types=1);

namespace Acme\Runtime;

use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;
use SocKit\Operations\Contracts\MissingReceiptPolicy;
use SocKit\Operations\Contracts\MutationAuthorityFence;

final class RuntimeProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add(app: $this->app, modules: RuntimeModule::class);
        Modules::add(app: $this->app, modules: LinksModule::class);
        $this->app->bind(MutationAuthorityFence::class, AuthorityRouter::class);
        $this->app->bind(MissingReceiptPolicy::class, EnvelopeMissingReceiptPolicy::class);
    }
}
