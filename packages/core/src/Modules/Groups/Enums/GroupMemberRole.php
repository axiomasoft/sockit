<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum GroupMemberRole: string
{
    case Owner = 'owner';
    case Moderator = 'moderator';
    case Member = 'member';
}
