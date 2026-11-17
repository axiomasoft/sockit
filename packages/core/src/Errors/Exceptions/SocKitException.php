<?php

declare(strict_types=1);

namespace SocKit\Errors\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Exceptions\CodedException;
use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Errors\Enums\SockitErrorCode;
use Throwable;

/**
 * Base domain exception for all typed SocKit failures backed by {@see SockitErrorCode}.
 */
#[Api(since: '1.0')]
abstract class SocKitException extends CodedException implements ProvidesProblem
{
    /** @param array<string, scalar|list<scalar|null>|null> $detailMap */
    public function __construct(
        private readonly SockitErrorCode $problemCode,
        string $message,
        private readonly array $detailMap = [],
        private readonly ?string $pointer = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    #[Override]
    public function problem(): SockitErrorCode
    {
        return $this->problemCode;
    }

    /** @return array<string, scalar|list<scalar|null>|null> */
    #[Override]
    public function detail(): array
    {
        return $this->detailMap;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: $this->problemCode,
            category: $this->problemCode->category(),
            retryable: $this->problemCode->isRetryable(),
            path: $this->pointer,
            details: $this->detailMap,
        );
    }
}
