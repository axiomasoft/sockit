<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum GroupMemberStatus: string
{
    case Active = 'active';
    case Removed = 'removed';
    case Banned = 'banned';
}
