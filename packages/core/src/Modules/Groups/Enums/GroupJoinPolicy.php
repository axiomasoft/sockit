<?php

declare(strict_types=1);

namespace SocKit\Modules\Groups\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum GroupJoinPolicy: string
{
    case Auto = 'auto';
    case Approval = 'approval';
    case Invite = 'invite';
    case Closed = 'closed';
}
