<?php

declare(strict_types=1);

namespace SocKit\Support\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class InvalidPayloadException extends SocKitException
{
    public function problem(): ProblemCode
    {
        return ProblemCode::PayloadInvalid;
    }
}
