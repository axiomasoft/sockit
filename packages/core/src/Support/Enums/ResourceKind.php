<?php

declare(strict_types=1);

namespace SocKit\Support\Enums;

use Packman\Attributes\Api;
use SocKit\Support\Data\ResourceRef;

/**
 * Closed set of SocKit-owned resource kinds addressable via {@see ResourceRef}.
 */
#[Api(since: '1.0')]
enum ResourceKind: string
{
    case SurfaceInstance = 'surface_instance';
    case Post = 'post';
    case Group = 'group';
    case Reaction = 'reaction';
}
