<?php

declare(strict_types=1);

namespace SocKit\Audience\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class AuthorizationDeniedException extends SocKitException
{
    public static function denied(string $reason): self
    {
        return new self(
            SockitErrorCode::AuthorizationDenied,
            "Action authorization denied [{$reason}].",
            ['reason' => $reason],
        );
    }

    public static function unknown(string $reason): self
    {
        return new self(
            SockitErrorCode::AuthorizationUnknown,
            "Action authorization unknown [{$reason}].",
            ['reason' => $reason],
        );
    }
}
