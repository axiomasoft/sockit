<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class OtherTargetModule extends TargetModule
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('acme.other');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $this->draft = $registrar;
        $registrar->target(alias: 'acme.target', resolver: OtherTargetAdapter::class);
    }
}
