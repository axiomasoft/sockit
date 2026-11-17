<?php

declare(strict_types=1);

namespace SocKit\Errors\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Errors\Enums\SockitErrorCode;

/**
 * Marks exceptions that carry a machine-readable {@see SockitErrorCode} and bounded scalar detail map.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ProvidesProblem
{
    public function problem(): SockitErrorCode;

    /** @return array<string, scalar|list<scalar|null>|null> */
    public function detail(): array;
}
