<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Exceptions\InvalidCanonicalPayloadException;

final class DigestHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = $fixture['input'];
        $expected = $fixture['expect'];
        if (! is_array($input) || ! is_array($expected) || ! isset($input['command'], $input['payload']) || ! is_string($input['command']) || ! is_array($input['payload'])) {
            throw new \RuntimeException('Invalid digest fixture');
        }
        if (array_key_exists('compare', $input)) {
            if (! is_array($input['compare']) || ! isset($expected['equal']) || ! is_bool($expected['equal'])) {
                throw new \RuntimeException('Invalid comparison fixture');
            }
            $first = PayloadDigest::of(commandType: $input['command'], payload: $input['payload']);
            $second = PayloadDigest::of(commandType: $input['command'], payload: $input['compare']);
            expect($first->value === $second->value)->toBe($expected['equal']);

            return;
        }
        try {
            $canonical = CanonicalJson::encode($input['payload']);
            $digest = PayloadDigest::of(commandType: $input['command'], payload: $input['payload']);
            expect($expected['canonical'])->toBe($canonical)->and($expected['digest'])->toBe($digest->value);
        } catch (InvalidCanonicalPayloadException $e) {
            expect($expected['invalid'] ?? false)->toBeTrue()->and($e->problem()->value)->toBe('sockit.payload_invalid');
        }
    }
}
