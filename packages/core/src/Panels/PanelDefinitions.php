<?php

declare(strict_types=1);

namespace SocKit\Panels;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\PanelNotFoundException;
use SocKit\Panels\Internal\PanelGraphCompiler;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class PanelDefinitions
{
    /** @param array<string, PanelDefinition> $definitions */
    private function __construct(private array $definitions) {}

    public static function configure(Application $app): void
    {
        self::compiler($app);
        $app->singleton(abstract: self::class, concrete: static fn (): self => new self($app->make(PanelGraphCompiler::class)->published()));
    }

    public static function add(Application $app, string ...$providers): void
    {
        foreach ($providers as $provider) {
            self::compiler($app)->add($provider);
        }
    }

    /** Called with a private module view by the booting coordinator. */
    public static function compile(Application $app, SocKitConfig $config, Modules $modules): self
    {
        return new self(self::compiler($app)->compile(app: $app, config: $config, modules: $modules));
    }

    public static function publish(Application $app, self $draft): void
    {
        self::compiler($app)->publish($draft->definitions);
    }

    private static function compiler(Application $app): PanelGraphCompiler
    {
        if (! $app->bound(PanelGraphCompiler::class)) {
            $app->singleton(PanelGraphCompiler::class);
        }

        return $app->make(PanelGraphCompiler::class);
    }

    public function get(PanelId $id): PanelDefinition
    {
        return $this->definitions[$id->value] ?? throw new PanelNotFoundException(message: 'Panel is not defined', detail: ['panel' => $id->value]);
    }

    public function has(PanelId $id): bool
    {
        return isset($this->definitions[$id->value]);
    }

    /** @return array<string, PanelDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }
}
