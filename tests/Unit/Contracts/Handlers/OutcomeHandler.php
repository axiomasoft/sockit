<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Exceptions\InvalidOperationResultException;

final class OutcomeHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = $fixture['input'];
        $expected = $fixture['expect'];
        if (! is_array($input) || ! is_array($expected) || ! isset($expected['valid']) || ! is_bool($expected['valid'])) {
            throw new \RuntimeException('Invalid outcome fixture');
        }
        $wire = [];
        foreach ($input as $key => $value) {
            if (! is_string($key)) {
                throw new \RuntimeException('Invalid outcome key');
            }
            $wire[$key] = $value;
        }
        try {
            $result = OperationResult::fromWire($wire);
            expect($expected['valid'])->toBeTrue()->and($result->toWire())->toBe($wire)->and($result->isSuccess())->toBe($wire['outcome'] === 'confirmed');
        } catch (InvalidOperationResultException $e) {
            expect($expected['valid'])->toBeFalse()->and($e->problem()->value)->toBe('sockit.payload_invalid');
        }
    }
}
