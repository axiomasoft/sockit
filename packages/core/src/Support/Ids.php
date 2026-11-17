<?php

declare(strict_types=1);

namespace SocKit\Support;

use Packman\Attributes\Api;
use Ramsey\Uuid\Uuid;

/**
 * Generates monotonic time-ordered UUIDv7 identifiers without exposing the underlying UUID library.
 */
#[Api(since: '1.0')]
final class Ids
{
    public static function uuid7(): string
    {
        return Uuid::uuid7()->toString();
    }
}
