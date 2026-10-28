<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class DependentModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(id: 'acme.demo-links');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->schema(DependentContribution::class);
    }
}
