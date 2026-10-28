<?php

declare(strict_types=1);

namespace SocKit\Attributes;

#[Api]
enum SpiKind: string
{
    case Implement = 'implement';
    case Call = 'call';
    case Extend = 'extend';
}
