<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum SurfaceLifecycleState: string
{
    case Active = 'active';
    case Archived = 'archived';
}
