<?php

declare(strict_types=1);

namespace SocKit\Modules;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Modules\Internal\CompiledModuleGraph;
use SocKit\Modules\Internal\ModuleGraphCompiler;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class Modules
{
    private function __construct(private CompiledModuleGraph $graph) {}

    /** Provider lifecycle wiring, before module providers register. */
    public static function configure(Application $app): void
    {
        self::compiler($app);
        $app->singleton(abstract: self::class, concrete: fn (): self => new self($app->make(ModuleGraphCompiler::class)->published()));
    }

    /** Compile a private candidate. Registry resolution still fails until publish. */
    public static function compile(Application $app): self
    {
        return new self(self::compiler($app)->compile(app: $app, config: $app->make(SocKitConfig::class)));
    }

    public static function publish(Application $app, self $draft): void
    {
        self::compiler($app)->publish($draft->graph);
    }

    private static function compiler(Application $app): ModuleGraphCompiler
    {
        if (! $app->bound(ModuleGraphCompiler::class)) {
            $app->singleton(ModuleGraphCompiler::class);
        }

        return $app->make(ModuleGraphCompiler::class);
    }

    public static function add(Application $app, string ...$modules): void
    {
        foreach ($modules as $module) {
            self::compiler($app)->add($module);
        }
    }

    public static function module(Application $app, string $module): bool
    {
        self::add(app: $app, modules: $module);
        if (! SocKitConfig::implementation(value: $module, contract: Contracts\Module::class)) {
            throw new Exceptions\InvalidModuleException([['module' => $module, 'key' => 'modules', 'rule' => 'MODULE_IMPLEMENTATION_INVALID']]);
        }
        $instance = $app->make($module);
        if (! $instance instanceof Contracts\Module) {
            throw new Exceptions\InvalidModuleException([['module' => $module, 'key' => 'modules', 'rule' => 'MODULE_IMPLEMENTATION_INVALID']]);
        }

        return in_array($instance->manifest()->id, SocKitConfig::fromRepository($app->make('config'))->modules->enabled, true);
    }

    /** @return array<string, ModuleManifest> */
    public function manifests(): array
    {
        return $this->graph->manifests;
    }

    /** @return array<string, array{surfaces:list<string>, features:list<string>, settings:class-string<ModuleSettingsSchema>|null}> */
    public function declarations(): array
    {
        return $this->graph->declarations;
    }

    /** @return array<string, array<string, scalar|array<array-key, mixed>|null>> */
    public function settings(): array
    {
        return $this->graph->settings;
    }
}
