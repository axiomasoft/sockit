<?php

declare(strict_types=1);

namespace SocKit\Errors\Data;

use Packman\Attributes\Api;
use SocKit\Errors\Enums\SockitErrorCode;

/**
 * Immutable specification metadata for a registered SocKit problem code.
 */
#[Api(since: '1.0')]
final readonly class ProblemSpec
{
    public function __construct(
        public SockitErrorCode $code,
        public int $httpStatus,
        public bool $retryable,
        public bool $receiptRecorded,
        public string $title,
    ) {}

    /** @return array{code: string, http_status: int, retryable: bool, receipt_recorded: bool, title: string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'http_status' => $this->httpStatus,
            'retryable' => $this->retryable,
            'receipt_recorded' => $this->receiptRecorded,
            'title' => $this->title,
        ];
    }
}
