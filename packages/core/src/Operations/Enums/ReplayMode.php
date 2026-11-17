<?php

declare(strict_types=1);

namespace SocKit\Operations\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ReplayMode: string
{
    case Normal = 'normal';
    case ProjectionOnly = 'projection_only';
}
