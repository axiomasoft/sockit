<?php

declare(strict_types=1);

namespace SocKit\Groups\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum GroupVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Secret = 'secret';
}
