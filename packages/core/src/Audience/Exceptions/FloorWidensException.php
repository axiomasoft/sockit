<?php

declare(strict_types=1);

namespace SocKit\Audience\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class FloorWidensException extends SocKitException
{
    public static function widened(string $reason, ?string $pointer = null): self
    {
        return new self(
            SockitErrorCode::ConfigurationInvalid,
            $reason,
            ['rule' => 'floor_narrowing', 'reason' => $reason],
            $pointer,
        );
    }
}
