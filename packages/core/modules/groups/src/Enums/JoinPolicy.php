<?php

declare(strict_types=1);

namespace SocKit\Groups\Enums;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
enum JoinPolicy: string
{
    case Auto = 'auto';
    case Approval = 'approval';
    case Invite = 'invite';
    case Closed = 'closed';
}
