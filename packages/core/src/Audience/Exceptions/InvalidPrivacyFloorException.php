<?php

declare(strict_types=1);

namespace SocKit\Audience\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class InvalidPrivacyFloorException extends SocKitException
{
    public static function rule(string $rule, string $message, ?string $pointer = null): self
    {
        return new self(
            SockitErrorCode::ConfigurationInvalid,
            $message,
            ['rule' => $rule],
            $pointer,
        );
    }
}
