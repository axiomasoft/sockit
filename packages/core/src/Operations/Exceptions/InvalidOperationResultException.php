<?php

declare(strict_types=1);

namespace SocKit\Operations\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class InvalidOperationResultException extends SocKitException
{
    public function problem(): ProblemCode
    {
        return ProblemCode::PayloadInvalid;
    }
}
