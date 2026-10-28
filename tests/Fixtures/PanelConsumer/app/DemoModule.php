<?php

declare(strict_types=1);

namespace Acme;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

/** Test-only schema owner; no production reactions implementation. */
final class DemoModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(id: 'acme.demo-targets');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->schema(DemoContribution::class);
    }
}
