<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use SocKit\Modules\Contracts\Module;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class GroupsModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(id: 'sockit.groups', databases: ['pgsql']);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->feature('groups')->settings(GroupsSettings::class)->schema(InitialSchema::class);
    }
}
