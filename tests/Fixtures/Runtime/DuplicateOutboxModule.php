<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class DuplicateOutboxModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('acme.other');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->outboxConsumer(id: 'acme.inbox', consumer: SqlInboxConsumer::class);
    }
}
