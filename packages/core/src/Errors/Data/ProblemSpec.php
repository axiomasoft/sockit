<?php

declare(strict_types=1);

namespace SocKit\Errors\Data;

use SocKit\Attributes\Api;

#[Api]
final readonly class ProblemSpec
{
    public function __construct(public string $code, public int $httpStatus, public bool $retryable, public bool $receiptRecorded, public string $title) {}
}
