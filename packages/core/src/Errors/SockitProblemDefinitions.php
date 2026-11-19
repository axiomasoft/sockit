<?php

declare(strict_types=1);

namespace SocKit\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use SocKit\Errors\Enums\ProblemCode;

/**
 * Registers RFC 9457 problem definitions for every {@see ProblemCode} case.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class SockitProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(private string $urnPrefix = 'urn:packman:problem:') {}

    #[Override]
    public function definitions(): iterable
    {
        foreach (ProblemCode::cases() as $code) {
            $spec = $code->spec();
            yield new ProblemDefinition(
                code: $code,
                type: $this->urnPrefix.$code->value,
                defaultStatus: match ($code) {
                    ProblemCode::ContentHeld => 409,
                    ProblemCode::ExternalOutcomeUnknown => 503,
                    default => $spec->httpStatus,
                },
                title: $spec->title,
                retryable: $spec->retryable,
                category: $code->category(),
            );
        }
    }
}
