<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Exceptions;

use SocKit\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class UnknownSurfaceKindException extends SocKitException
{
    public function problem(): ProblemCode
    {
        return ProblemCode::SurfaceNotFound;
    }
}
