<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Storage;

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

final class SurfaceStateException extends SocKitException
{
    public function __construct(private readonly ProblemCode $codeValue)
    {
        parent::__construct('Surface state rejected');
    }

    public function problem(): ProblemCode
    {
        return $this->codeValue;
    }
}
