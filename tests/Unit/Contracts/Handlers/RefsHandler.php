<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;

final class RefsHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = $fixture['input'];
        $expected = $fixture['expect'] ?? null;
        if (! is_array($input) || ! is_array($expected) || ! isset($input['kind'], $input['value'], $expected['valid']) || ! is_string($input['kind']) || ! is_bool($expected['valid'])) {
            throw new \RuntimeException('Invalid refs fixture');
        }
        $valid = $expected['valid'];
        $value = $input['value'];
        $object = [];
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (! is_string($key)) {
                    throw new \RuntimeException('Invalid wire key');
                }
                $object[$key] = $item;
            }
        }
        if (in_array($input['kind'], ['host', 'actor', 'panel'], true) && ! is_string($value)) {
            throw new \RuntimeException('Invalid string fixture');
        }
        if (in_array($input['kind'], ['resource', 'surface'], true) && ! is_array($value)) {
            throw new \RuntimeException('Invalid object fixture');
        }
        try {
            $ref = match ($input['kind']) {
                'host' => HostEntityRef::fromWire(is_string($value) ? $value : throw new \RuntimeException('Invalid host')),
                'actor' => ActorRef::fromWire(is_string($value) ? $value : throw new \RuntimeException('Invalid actor')),
                'panel' => PanelId::fromWire(is_string($value) ? $value : throw new \RuntimeException('Invalid panel')),
                'resource' => ResourceRef::fromWire($object),
                'surface' => SurfaceRef::fromWire($object),
                default => throw new \RuntimeException('Unknown reference fixture'),
            };
            expect($valid)->toBeTrue()->and($ref->toWire())->toBe($input['value']);
        } catch (InvalidPayloadException $e) {
            expect($valid)->toBeFalse()->and($e->problem()->value)->toBe('sockit.payload_invalid');
        }
    }
}
