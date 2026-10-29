<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class ReferencedSettingsModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('review.module');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->settings(ReferencedSettingsSchema::class);
    }
}
