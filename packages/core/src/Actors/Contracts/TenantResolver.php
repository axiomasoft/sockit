<?php

declare(strict_types=1);

namespace SocKit\Actors\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Support\Data\InstallationRef;

/**
 * Host SPI identifying the active installation/tenant isolation boundary.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TenantResolver
{
    public function current(): InstallationRef;
}
