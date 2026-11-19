<?php

declare(strict_types=1);

namespace SocKit\Groups\Exceptions;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Api(since: '1.0')]
final class GroupException extends SocKitException
{
    public function __construct(private readonly ProblemCode $codeValue)
    {
        parent::__construct($codeValue->value);
    }

    public function problem(): ProblemCode
    {
        return $this->codeValue;
    }
}
