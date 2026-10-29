<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use SocKit\Modules\Modules;
use SocKit\SocKitServiceProvider;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;

final class TargetScenario
{
    /** @param list<string> $enabled
     * @param list<string> $selected
     * @param list<class-string> $offered */
    public static function app(array $enabled = ['acme.runtime', 'acme.other'], array $selected = ['acme.runtime'], array $offered = [TargetModule::class, OtherTargetModule::class], bool $boot = true, ?string $fallback = TargetAdapter::class): Application
    {
        $app = new Application;
        $app->instance('config', new Repository(['sockit' => ['panels' => [TargetPanel::class], 'surfaces' => [RuntimeSurface::class],
            'modules' => ['enabled' => $enabled], 'resolvers' => ['targets' => $fallback]]]));
        $app->instance(TargetPanel::class, new TargetPanel($selected));
        $app->register(SocKitServiceProvider::class);
        foreach ($offered as $module) {
            Modules::add(app: $app, modules: $module);
        }
        if ($boot) {
            $app->boot();
        }

        return $app;
    }

    public static function target(string $alias = 'acme.target', string $key = '00042', int $incarnation = 1): TargetRef
    {
        return new TargetRef(host: new HostEntityRef(type: $alias, value: $key), incarnation: new Incarnation($incarnation));
    }
}
