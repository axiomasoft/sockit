<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class ReactionsModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('social.reactions');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->feature('reactions');
    }
}
