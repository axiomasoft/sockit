<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Panels;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use SocKit\Modules\Modules;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Modules\DemoModule;
use SocKit\Tests\Fixtures\Modules\OtherModule;
use SocKit\Tests\Unit\Audience\Support\Scenario as Wire;

final class Scenario
{
    /** @param array<string, mixed> $input */
    public static function app(array $input): Application
    {
        $recipes = Wire::entries($input['panels'] ?? []);
        $providers = [];
        $app = new Application;
        foreach ($recipes as $index => $recipe) {
            if ($index > 1) {
                throw new \RuntimeException('Panel fixture supports at most two recipes');
            }
            $class = $index === 0 ? ConfiguredPanel::class : SecondConfiguredPanel::class;
            $app->instance($class, new $class(Wire::object($recipe)));
            $providers[] = $class;
        }
        $app->instance('config', new Repository(['sockit' => ['panels' => $providers, 'modules' => ['enabled' => ['demo.module', 'other.module'], 'settings' => ['demo.module' => ['limit' => 20]]]]]));
        $app->register(SocKitServiceProvider::class);
        Modules::add($app, DemoModule::class, OtherModule::class);

        return $app;
    }
}
