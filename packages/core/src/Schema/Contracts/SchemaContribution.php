<?php

declare(strict_types=1);

namespace SocKit\Schema\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Schema\SchemaComponent;

/** Module-owned immutable schema history; no SQL or current operation context. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SchemaContribution
{
    public function component(): SchemaComponent;
}
