<?php

declare(strict_types=1);

namespace SocKit\Operations\Enums;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
enum ReplayMode: string
{
    case Delivery = 'delivery';
    case ProjectionOnly = 'projection_only';
}
