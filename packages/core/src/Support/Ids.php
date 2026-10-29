<?php

declare(strict_types=1);

namespace SocKit\Support;

use Ramsey\Uuid\Uuid;
use SocKit\Attributes\Api;

#[Api(since: '1.0')]
final class Ids
{
    public static function uuid7(): string
    {
        return Uuid::uuid7()->toString();
    }
}
