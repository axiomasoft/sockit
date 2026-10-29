<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use SocKit\Errors\Contracts\ProvidesProblem;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;

final class TargetsHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = $fixture['input'];
        $expected = $fixture['expect'];
        if (! is_array($input) || ! is_array($expected) || ! is_string($input['kind'] ?? null) || ! is_array($input['wire'] ?? null)) {
            throw new \RuntimeException('Invalid target fixture');
        }
        $wire = [];
        foreach ($input['wire'] as $key => $value) {
            if (! is_string($key)) {
                throw new \RuntimeException('Invalid target wire key');
            }
            $wire[$key] = $value;
        }
        try {
            $ref = match ($input['kind']) {
                'ref' => TargetRef::fromWire($wire),
                'resolution' => TargetResolution::fromWire($wire),
                default => throw new \RuntimeException('Unknown target fixture'),
            };
            expect($expected['valid'])->toBeTrue()->and($ref->toWire())->toBe($wire);
        } catch (\Throwable $error) {
            if (! $error instanceof ProvidesProblem) {
                throw $error;
            }
            expect($expected['valid'])->toBeFalse()->and($error->problem()->value)->toBe($expected['problem']);
        }
    }
}
