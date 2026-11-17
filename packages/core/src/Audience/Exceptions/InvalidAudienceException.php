<?php

declare(strict_types=1);

namespace SocKit\Audience\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class InvalidAudienceException extends SocKitException
{
    public static function unsupportedVersion(string $version): self
    {
        return new self(
            SockitErrorCode::AudienceVersionUnknown,
            "Unsupported AudienceSpec version [{$version}].",
            ['version' => $version],
            '/version',
        );
    }

    public static function invalidSpec(string $message, ?string $pointer = null): self
    {
        return new self(
            SockitErrorCode::PayloadInvalid,
            $message,
            [],
            $pointer,
        );
    }
}
