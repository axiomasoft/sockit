<?php

declare(strict_types=1);

namespace SocKit\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use SocKit\Errors\Enums\SockitErrorCode;

/**
 * Registers RFC 9457 problem definitions for every {@see SockitErrorCode} case.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class SockitProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    #[Override]
    public function definitions(): iterable
    {
        foreach (SockitErrorCode::cases() as $code) {
            yield new ProblemDefinition(
                code: $code,
                type: $this->urnPrefix.$code->value,
                defaultStatus: $code->defaultStatus(),
                title: $code->title(),
                retryable: $code->isRetryable(),
                category: $code->category(),
            );
        }
    }
}
