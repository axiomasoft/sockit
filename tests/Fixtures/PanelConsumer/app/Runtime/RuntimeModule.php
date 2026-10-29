<?php

declare(strict_types=1);

namespace Acme\Runtime;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class RuntimeModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('acme.runtime');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->outboxConsumer(id: 'acme.inbox', consumer: UnavailableConsumer::class);
    }
}
