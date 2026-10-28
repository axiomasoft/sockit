<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Enums;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
enum SurfaceLifecycle: string
{
    case Live = 'live';
    case Archived = 'archived';
    case Deleted = 'deleted';

    public function canRead(): bool
    {
        return $this !== self::Deleted;
    }

    public function canWrite(): bool
    {
        return $this === self::Live;
    }
}
