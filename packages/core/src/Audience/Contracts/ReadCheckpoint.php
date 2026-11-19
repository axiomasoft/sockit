<?php

declare(strict_types=1);

namespace SocKit\Audience\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** A read-only local state comparison. Implementations must never call policy or media adapters. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ReadCheckpoint
{
    public function isCurrent(): bool;
}
