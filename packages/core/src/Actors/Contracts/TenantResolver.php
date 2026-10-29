<?php

declare(strict_types=1);

namespace SocKit\Actors\Contracts;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Support\Data\InstallationRef;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TenantResolver
{
    public function current(): InstallationRef;
}
