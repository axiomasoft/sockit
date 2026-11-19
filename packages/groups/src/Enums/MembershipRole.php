<?php

declare(strict_types=1);

namespace SocKit\Groups\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum MembershipRole: string
{
    case Owner = 'owner';
    case Moderator = 'moderator';
    case Member = 'member';
}
