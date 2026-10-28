<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use SocKit\Modules\Modules;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Modules\DemoProvider;
use SocKit\Tests\Fixtures\Modules\OtherProvider;

it('collects every module provider before compiling regardless of core provider order', function (): void {
    $snapshots = [];
    foreach ([
        [SocKitServiceProvider::class, DemoProvider::class, OtherProvider::class],
        [DemoProvider::class, SocKitServiceProvider::class, OtherProvider::class],
        [OtherProvider::class, DemoProvider::class, SocKitServiceProvider::class],
        [SocKitServiceProvider::class, OtherProvider::class, DemoProvider::class],
        [OtherProvider::class, SocKitServiceProvider::class, DemoProvider::class],
        [DemoProvider::class, OtherProvider::class, SocKitServiceProvider::class],
    ] as $providers) {
        $app = new Application;
        $app->instance('config', new Repository(['sockit' => ['modules' => ['enabled' => ['demo.module', 'other.module']]]]));
        foreach ($providers as $provider) {
            $app->register($provider);
        }
        $app->boot();
        $snapshots[] = $app->make(Modules::class)->declarations();
    }
    foreach ($snapshots as $snapshot) {
        expect($snapshot)->toBe($snapshots[0])->toHaveCount(2);
    }
});
