<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum TargetLifecycle: string
{
    case Live = 'live';
    case ReadOnly = 'read_only';
    case Hidden = 'hidden';
    case Deleted = 'deleted';
    case Unknown = 'unknown';
}
