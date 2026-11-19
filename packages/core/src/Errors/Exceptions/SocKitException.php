<?php

declare(strict_types=1);

namespace SocKit\Errors\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Exceptions\CodedException;
use SocKit\Errors\Contracts\ProvidesProblem;

#[Api]
abstract class SocKitException extends CodedException implements ProvidesProblem
{
    /** @param array<string, scalar|null> $detail */
    public function __construct(string $message, private readonly array $detail = [], ?\Throwable $previous = null)
    {
        parent::__construct(message: $message, code: 0, previous: $previous);
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        $code = $this->problem();

        return new ErrorDescriptor($code, $code->category(), $code->spec()->retryable);
    }

    /** @return array<string, scalar|null> */
    public function detail(): array
    {
        return $this->detail;
    }
}
