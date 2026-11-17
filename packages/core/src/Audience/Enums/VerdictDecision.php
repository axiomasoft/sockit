<?php

declare(strict_types=1);

namespace SocKit\Audience\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum VerdictDecision: string
{
    case Allow = 'allow';
    case AllowReadOnly = 'allow_read_only';
    case Deny = 'deny';
}
