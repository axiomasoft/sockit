<?php

declare(strict_types=1);

namespace SocKit\Audience\Enums;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
enum ContourMembership: string
{
    case Live = 'live';
    case ReadOnly = 'read_only';
    case Archived = 'archived';
    case None = 'none';
}
