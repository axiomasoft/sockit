<?php

declare(strict_types=1);

namespace SocKit\Errors\Exceptions;

use SocKit\Attributes\Api;
use SocKit\Errors\Contracts\ProvidesProblem;

#[Api]
abstract class SocKitException extends \RuntimeException implements ProvidesProblem
{
    /** @param array<string, scalar|null> $detail */
    public function __construct(string $message, private readonly array $detail = [], ?\Throwable $previous = null)
    {
        parent::__construct(message: $message, code: 0, previous: $previous);
    }

    /** @return array<string, scalar|null> */
    public function detail(): array
    {
        return $this->detail;
    }
}
