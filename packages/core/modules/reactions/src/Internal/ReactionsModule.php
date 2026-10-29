<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class ReactionsModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(id: 'sockit.reactions', databases: ['pgsql']);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->feature('reactions')->settings(ReactionsSettings::class)->schema(InitialSchema::class);
    }
}
