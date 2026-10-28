<?php

declare(strict_types=1);

namespace Acme;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use SocKit\Modules\Modules;
use SocKit\Operations\Contracts\MissingReceiptPolicy;
use SocKit\Operations\Contracts\MutationAuthorityFence;

final class HostProvider extends ServiceProvider
{
    public function register(): void
    {
        Modules::add(app: $this->app, modules: RuntimeModule::class);
        $connection = fn () => $this->app->make(DatabaseManager::class)->connection('owner_a');
        $this->app->bind(EnvelopeTarget::class, fn () => new EnvelopeTarget($connection()));
        $this->app->bind(EnvelopeAuthority::class, fn () => new EnvelopeAuthority($connection()));
        $this->app->bind(MutationAuthorityFence::class, AuthorityRouter::class);
        $this->app->bind(MissingReceiptPolicy::class, EnvelopeMissingReceiptPolicy::class);
        $this->app->bind(SqlInboxConsumer::class, fn () => new SqlInboxConsumer(owner: $connection(), consumer: PgTarget::fromEnvironment()->connect()));
    }
}
