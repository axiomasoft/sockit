<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

class TargetModule implements Module
{
    public ?ModuleRegistrar $draft = null;

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('acme.runtime');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $this->draft = $registrar;
        $registrar->target(alias: 'acme.target', resolver: TargetAdapter::class);
    }
}
