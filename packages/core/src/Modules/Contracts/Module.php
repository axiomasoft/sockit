<?php

declare(strict_types=1);

namespace SocKit\Modules\Contracts;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface Module
{
    public function manifest(): ModuleManifest;

    /** Declare capabilities only: no IO, bindings, commands or operation state. */
    public function register(ModuleRegistrar $registrar): void;
}
