<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class OutboxModule implements Module
{
    public ?ModuleRegistrar $draft = null;

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('acme.runtime');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $this->draft = $registrar;
        $registrar->outboxConsumer(id: 'acme.inbox', consumer: SqlInboxConsumer::class);
    }
}
