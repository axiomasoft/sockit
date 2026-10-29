<?php

declare(strict_types=1);

use SocKit\Attributes\Api;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;

it('exposes the exact attribute contract and string backed SPI kinds', function (): void {
    expect((new Api)->since)->toBe('1.0')
        ->and((new Api(since: '2.0'))->since)->toBe('2.0')
        ->and((new Spi(kind: SpiKind::Implement))->kind)->toBe(SpiKind::Implement)
        ->and((new Spi(kind: SpiKind::Call))->since)->toBe('1.0')
        ->and((new Spi(kind: SpiKind::Extend, since: '2.0'))->since)->toBe('2.0')
        ->and(array_column(SpiKind::cases(), 'value'))->toBe(['implement', 'call', 'extend']);
});

it('makes the marker types final readonly class attributes with an API marker', function (Api|Spi $marker): void {
    $type = new ReflectionClass($marker);
    expect($type->isFinal())->toBeTrue()->and($type->isReadOnly())->toBeTrue();
    $attribute = $type->getAttributes(Attribute::class)[0]->newInstance();
    expect($attribute->flags)->toBe(Attribute::TARGET_CLASS);
    $api = $type->getAttributes(Api::class)[0]->newInstance();
    expect($api->since)->toBe('1.0');
})->with(['API' => [new Api], 'SPI' => [new Spi(kind: SpiKind::Implement)]]);
