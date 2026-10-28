<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Panels;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class NestedModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(id: 'nested.module', resourceRoot: null);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->settings(NestedSettingsSchema::class);
    }
}
