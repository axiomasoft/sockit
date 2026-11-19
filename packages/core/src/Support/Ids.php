<?php

declare(strict_types=1);

namespace SocKit\Support;

use Packman\Attributes\Api;
use Ramsey\Uuid\Uuid;

#[Api(since: '1.0')]
final class Ids
{
    public static function uuid7(): string
    {
        return Uuid::uuid7()->toString();
    }
}
