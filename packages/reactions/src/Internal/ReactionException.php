<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use Packman\Attributes\Internal;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

#[Internal]
final class ReactionException extends SocKitException
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
