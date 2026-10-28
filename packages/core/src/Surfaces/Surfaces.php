<?php

declare(strict_types=1);

namespace SocKit\Surfaces;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Modules;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Internal\SurfaceCompiler;

/** Boot coordinator API. Only the validated definitions contract is a runtime registry. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class Surfaces
{
    public static function configure(Application $app): void
    {
        self::compiler($app);
        $app->singleton(abstract: SurfaceDefinitions::class, concrete: static fn (): SurfaceDefinitions => self::compiler($app)->published());
    }

    public static function add(Application $app, string ...$providers): void
    {
        foreach ($providers as $provider) {
            self::compiler($app)->add($provider);
        }
    }

    public static function compile(Application $app, SocKitConfig $config, Modules $modules, PanelDefinitions $panels): void
    {
        self::compiler($app)->compile(app: $app, config: $config, modules: $modules, panels: $panels);
    }

    public static function publish(Application $app): void
    {
        self::compiler($app)->publish();
    }

    private static function compiler(Application $app): SurfaceCompiler
    {
        if (! $app->bound(SurfaceCompiler::class)) {
            $app->singleton(SurfaceCompiler::class);
        }

        return $app->make(SurfaceCompiler::class);
    }
}
