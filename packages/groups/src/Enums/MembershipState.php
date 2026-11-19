<?php

declare(strict_types=1);

namespace SocKit\Groups\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum MembershipState: string
{
    case Pending = 'pending';
    case Invited = 'invited';
    case Active = 'active';
    case Left = 'left';
}
