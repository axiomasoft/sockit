<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Envelope;

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;

final class EnvelopeException extends SocKitException
{
    public function __construct(private readonly ProblemCode $value)
    {
        parent::__construct('Operation boundary rejected');
    }

    public function problem(): ProblemCode
    {
        return $this->value;
    }
}
