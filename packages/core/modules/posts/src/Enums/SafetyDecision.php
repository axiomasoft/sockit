<?php

declare(strict_types=1);

namespace SocKit\Posts\Enums;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
enum SafetyDecision: string
{
    case Allow = 'allow';
    case Hold = 'hold';
    case Reject = 'reject';
    case Unknown = 'unknown';
}
