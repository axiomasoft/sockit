<?php

declare(strict_types=1);

use SocKit\SocKitServiceProvider;

it('boots the provider', function (): void {
    expect(config('sockit'))->toBeArray()
        ->and(app()->getProviders(SocKitServiceProvider::class))->not->toBeEmpty();
});
