<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Modules;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class OtherModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('other.module');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->feature('other');
    }
}
