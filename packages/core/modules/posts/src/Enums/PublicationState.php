<?php

declare(strict_types=1);

namespace SocKit\Posts\Enums;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
enum PublicationState: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Held = 'held';
    case Rejected = 'rejected';
}
