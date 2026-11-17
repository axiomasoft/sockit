<?php

declare(strict_types=1);

namespace SocKit\Actors\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

/**
 * Thrown when an actor context violates trusted invariants or does not match the active installation.
 */
#[Api(since: '1.0')]
final class ActorContextMismatchException extends SocKitException
{
    /** @param array<string, scalar|list<scalar|null>|null> $detail */
    public static function installationMismatch(string $expected, string $actual, array $detail = []): self
    {
        return new self(
            SockitErrorCode::ActorContextInvalid,
            "ActorContext installation [{$actual}] does not match active installation [{$expected}].",
            ['expected' => $expected, 'actual' => $actual, ...$detail],
            '/installation',
        );
    }

    public static function invalidInvariant(string $message, ?string $pointer = null): self
    {
        return new self(SockitErrorCode::ActorContextInvalid, $message, [], $pointer);
    }
}
