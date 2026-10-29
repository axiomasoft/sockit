<?php

declare(strict_types=1);

namespace SocKit\Actors\Enums;

use SocKit\Attributes\Api;

#[Api(since: '1.0')]
enum ActorKind: string
{
    case Person = 'person';
    case Service = 'service';
    case System = 'system';
    case Anonymous = 'anonymous';
}
