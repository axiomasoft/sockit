<?php

declare(strict_types=1);

namespace SocKit\Support\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ResourceKind: string
{
    case SurfaceInstance = 'surface_instance';
    case Post = 'post';
    case Group = 'group';
    case Reaction = 'reaction';
}
