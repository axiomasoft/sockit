<?php

declare(strict_types=1);

namespace SocKit\Actors\Enums;

use Packman\Attributes\Api;

/**
 * Age-band classification; Unknown is conservatively treated as Minor for privacy floors.
 */
#[Api(since: '1.0')]
enum AgeBand: string
{
    case Adult = 'adult';
    case Minor = 'minor';
    case Unknown = 'unknown';
}
