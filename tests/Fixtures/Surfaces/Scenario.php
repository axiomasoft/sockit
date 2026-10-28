<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario as Wire;

final class Scenario
{
    /** @param array<string, mixed> $input */
    public static function app(array $input = []): Application
    {
        $app = new Application;
        $providers = [];
        foreach (Wire::entries($input['surfaces'] ?? []) as $index => $recipe) {
            $class = [ConfiguredSurface::class, SecondConfiguredSurface::class, ThirdConfiguredSurface::class][$index] ?? throw new \RuntimeException('Too many fixture providers');
            $app->instance($class, new $class(Wire::object($recipe)));
            $providers[] = $class;
        }
        $app->instance('config', new Repository(['sockit' => ['surfaces' => $providers,
            'privacy' => ['floor' => Wire::floorWire()], 'modules' => ['enabled' => ['social.posts', 'social.reactions']]]]));
        $app->register(SocKitServiceProvider::class);
        $app->register(SocialServiceProvider::class);
        $app->register(PanelServiceProvider::class);

        return $app;
    }
}
