<?php

declare(strict_types=1);

namespace SocKit\Actors\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

/**
 * Thrown when an actor context cannot be resolved from the current host principal or worker identity.
 */
#[Api(since: '1.0')]
final class UnresolvedActorException extends SocKitException
{
    public static function unauthenticated(string $reason = 'No authenticated principal available.'): self
    {
        return new self(SockitErrorCode::AuthenticationRequired, $reason, ['reason' => $reason]);
    }
}
