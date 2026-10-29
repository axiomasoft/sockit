<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Exceptions\InvalidPayloadException;

final class CommandHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = $fixture['input'];
        $expected = $fixture['expect'] ?? null;
        if (! is_array($input) || ! is_array($expected) || ! isset($input['kind'], $input['value'], $expected['valid']) || ! is_string($input['kind']) || ! is_string($input['value']) || ! is_bool($expected['valid'])) {
            throw new \RuntimeException('Invalid command fixture');
        }
        $valid = $expected['valid'];
        try {
            $ref = match ($input['kind']) {
                'key' => CommandKey::fromWire($input['value']),
                'namespace' => CommandNamespace::fromWire($input['value']),
                default => throw new \RuntimeException('Unknown command fixture'),
            };
            expect($valid)->toBeTrue()->and($ref->toWire())->toBe($input['value']);
        } catch (InvalidPayloadException $e) {
            expect($valid)->toBeFalse()->and($e->problem()->value)->toBe('sockit.payload_invalid');
        }
    }
}
