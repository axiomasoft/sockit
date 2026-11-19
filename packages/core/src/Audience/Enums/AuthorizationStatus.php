<?php

declare(strict_types=1);

namespace SocKit\Audience\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum AuthorizationStatus: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Unknown = 'unknown';
}
