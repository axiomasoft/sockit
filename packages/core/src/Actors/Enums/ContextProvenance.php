<?php

declare(strict_types=1);

namespace SocKit\Actors\Enums;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
enum ContextProvenance: string
{
    case Http = 'http';
    case Direct = 'direct';
    case Worker = 'worker';
    case System = 'system';
}
