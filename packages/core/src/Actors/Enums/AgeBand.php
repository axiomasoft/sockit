<?php

declare(strict_types=1);

namespace SocKit\Actors\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum AgeBand: string
{
    case Adult = 'adult';
    case Minor = 'minor';
    case Unknown = 'unknown';
}
