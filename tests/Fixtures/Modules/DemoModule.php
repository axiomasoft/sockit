<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Modules;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class DemoModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(id: 'demo.module', databases: ['sqlite']);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->feature('demo')->settings(DemoSettingsSchema::class);
    }
}
