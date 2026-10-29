<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use SocKit\Errors\Enums\ProblemCode;

final class ProblemsHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $actual = array_map(static function (ProblemCode $code): array {
            $spec = $code->spec();

            return ['code' => $spec->code, 'http' => $spec->httpStatus, 'retryable' => $spec->retryable, 'receipt' => $spec->receiptRecorded];
        }, ProblemCode::cases());
        if (! isset($fixture['expect']) || ! is_array($fixture['expect']) || ! isset($fixture['expect']['codes'])) {
            throw new \RuntimeException('Invalid problems fixture');
        }
        expect($fixture['expect']['codes'])->toEqualCanonicalizing($actual);
    }
}
