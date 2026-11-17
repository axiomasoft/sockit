<?php

declare(strict_types=1);
use SocKit\Configuration\SockitOptions;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\BetaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Core\Tests\Fixtures\PanelBetaProvider;
use SocKit\Modules\Posts\Configuration\PostsSettingsSchema;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\FrozenDefinitionException;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Panels\Internal\SockitPanelRegistry;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\SockitPanelBuilder;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Exceptions\SurfaceException;
use SocKit\Surfaces\Internal\SurfaceRegistry;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

test('compiled config roundtrips through var_export cache cleanly without closures', function (): void {
    $exported = var_export(config('sockit'), true);
    /** @var array<string, mixed> $reloaded */
    $reloaded = eval('return '.$exported.';');

    $options = SockitOptions::fromArray($reloaded);
    expect($options->idStrategy)->toBe('uuid7')
        ->and($options->tablePrefix->value())->toBe('sk_');
});

test('panel and surface provider compilation is strictly deterministic across registration permutations', function (): void {
    $options = SockitOptions::fromArray([
        'privacy' => [
            'floor' => [
                'allowed_scopes' => ['direct', 'group', 'contour', 'platform'],
                'default_scope' => 'platform',
                'max_scope' => 'platform',
                'minor_default_scope' => 'direct',
                'minor_max_scope' => 'group',
                'contour_levels' => ['team'],
            ],
        ],
    ]);
    $schemas = ['posts' => new PostsSettingsSchema];
    $connections = ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']];

    $regA = new SockitPanelRegistry;
    $regA->compileAndFreeze(
        providers: [PanelAlphaProvider::class, PanelBetaProvider::class],
        options: $options,
        activeModules: ['reactions', 'posts', 'groups'],
        moduleSchemas: $schemas,
        databaseConnections: $connections,
        defaultConnection: 'sqlite',
        providerResolver: static fn (string $cls): PanelProvider => new $cls,
    );

    $regB = new SockitPanelRegistry;
    $regB->compileAndFreeze(
        providers: [PanelBetaProvider::class, PanelAlphaProvider::class],
        options: $options,
        activeModules: ['groups', 'posts', 'reactions'],
        moduleSchemas: $schemas,
        databaseConnections: $connections,
        defaultConnection: 'sqlite',
        providerResolver: static fn (string $cls): PanelProvider => new $cls,
    );

    expect(array_keys($regA->all()))->toBe(['sockit.alpha', 'sockit.beta'])
        ->and(array_keys($regB->all()))->toBe(['sockit.alpha', 'sockit.beta'])
        ->and($regA->all())->toEqual($regB->all());

    $surfA = new SurfaceRegistry;
    $surfA->compileAndFreeze(
        providers: [AlphaWallSurfaceProvider::class, BetaWallSurfaceProvider::class],
        panels: $regA,
        providerResolver: static fn (string $cls): SurfaceProvider => new $cls,
    );

    $surfB = new SurfaceRegistry;
    $surfB->compileAndFreeze(
        providers: [BetaWallSurfaceProvider::class, AlphaWallSurfaceProvider::class],
        panels: $regB,
        providerResolver: static fn (string $cls): SurfaceProvider => new $cls,
    );

    expect(array_keys($surfA->all()))->toBe(['sockit.alpha/sockit.wall', 'sockit.beta/sockit.wall'])
        ->and(array_keys($surfB->all()))->toBe(['sockit.alpha/sockit.wall', 'sockit.beta/sockit.wall'])
        ->and($surfA->get(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'))->label)->toBe('Alpha Wall');
});

test('panel and surface registries reject early reads before freeze and late mutations after freeze', function (): void {
    $panels = new SockitPanelRegistry;
    $surfaces = new SurfaceRegistry;

    expect(fn (): array => $panels->all())->toThrow(FrozenDefinitionException::class);
    expect(fn (): array => $surfaces->all())->toThrow(FrozenDefinitionException::class);

    $panels->compileAndFreeze([], SockitOptions::fromArray([]), [], [], [], 'sqlite', static fn (string $c): PanelProvider => new $c);
    $surfaces->compileAndFreeze([], $panels, static fn (string $c): SurfaceProvider => new $c);

    expect(fn () => $panels->compileAndFreeze([], SockitOptions::fromArray([]), [], [], [], 'sqlite', static fn (string $c): PanelProvider => new $c))
        ->toThrow(FrozenDefinitionException::class);
    expect(fn () => $surfaces->compileAndFreeze([], $panels, static fn (string $c): SurfaceProvider => new $c))
        ->toThrow(FrozenDefinitionException::class);
});

test('invalid unused panel and surface declarations fail closed at compile time', function (): void {
    $options = SockitOptions::fromArray([]);
    $connections = ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']];

    $inactiveModulePanel = new class extends PanelProvider
    {
        public function panel(SockitPanelBuilder $panel): SockitPanelBuilder
        {
            return $panel->id('sockit.invalid')->hostTypes('board')->modules('posts');
        }
    };

    $reg = new SockitPanelRegistry;
    expect(fn () => $reg->compileAndFreeze(
        [$inactiveModulePanel],
        $options,
        ['reactions'],
        [],
        $connections,
        'sqlite',
        static fn (string $c): PanelProvider => new $c,
    ))->toThrow(PanelException::class);

    $validPanel = new class extends PanelProvider
    {
        public function panel(SockitPanelBuilder $panel): SockitPanelBuilder
        {
            return $panel->id('sockit.only-reactions')->hostTypes('board')->modules('reactions');
        }
    };

    $reg2 = new SockitPanelRegistry;
    $reg2->compileAndFreeze([$validPanel], $options, ['reactions'], [], $connections, 'sqlite', static fn (string $c): PanelProvider => new $c);

    $badHostSurface = new class extends SurfaceProvider
    {
        public function surface(Surface $surface): Surface
        {
            return $surface->panel(new PanelId('sockit.only-reactions'))->kind('sockit.wall')->host('article');
        }
    };

    $surfReg = new SurfaceRegistry;
    expect(fn () => $surfReg->compileAndFreeze([$badHostSurface], $reg2, static fn (string $c): SurfaceProvider => new $c))
        ->toThrow(SurfaceException::class);

    $unsupportedFeatureSurface = new class extends SurfaceProvider
    {
        public function surface(Surface $surface): Surface
        {
            return $surface->panel(new PanelId('sockit.only-reactions'))->kind('sockit.wall')->host('board')->features('posts');
        }
    };

    $surfReg2 = new SurfaceRegistry;
    expect(fn () => $surfReg2->compileAndFreeze([$unsupportedFeatureSurface], $reg2, static fn (string $c): SurfaceProvider => new $c))
        ->toThrow(PanelException::class);
});
