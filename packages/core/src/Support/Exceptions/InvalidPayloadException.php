<?php

declare(strict_types=1);

namespace SocKit\Support\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

/**
 * Thrown when payload canonicalization, digest input, or wire structure validation fails.
 */
#[Api(since: '1.0')]
final class InvalidPayloadException extends SocKitException
{
    /** @param array<string, scalar|list<scalar|null>|null> $detail */
    public static function invalid(string $reason, array $detail = [], ?string $pointer = null): self
    {
        return new self(SockitErrorCode::PayloadInvalid, $reason, $detail, $pointer);
    }

    /** @param array<string, scalar|list<scalar|null>|null> $detail */
    public static function unsupportedWireVersion(string $version, array $detail = []): self
    {
        return new self(
            SockitErrorCode::WireVersionUnsupported,
            "Unsupported wire version [{$version}].",
            ['version' => $version, ...$detail],
        );
    }
}
