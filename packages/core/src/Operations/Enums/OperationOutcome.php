<?php

declare(strict_types=1);

namespace SocKit\Operations\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum OperationOutcome: string
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Pending = 'pending';
    case Unknown = 'unknown';
}
