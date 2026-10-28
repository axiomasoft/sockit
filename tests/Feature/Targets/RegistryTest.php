<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\ModuleRegistrar;
use SocKit\Modules\Modules;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Surfaces;
use SocKit\Tests\Fixtures\Runtime\AlternateSurface;
use SocKit\Tests\Fixtures\Runtime\OtherTargetAdapter;
use SocKit\Tests\Fixtures\Runtime\OtherTargetModule;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Fixtures\Runtime\TargetAdapter;
use SocKit\Tests\Fixtures\Runtime\TargetModule;
use SocKit\Tests\Fixtures\Runtime\TargetOtherPanel;
use SocKit\Tests\Fixtures\Runtime\TargetScenario as Targets;

it('compiles the public target slot independent of offer order and freezes retained drafts', function (): void {
    $snapshots = [];
    foreach ([[TargetModule::class, OtherTargetModule::class], [OtherTargetModule::class, TargetModule::class]] as $order) {
        $app = Targets::app(offered: $order, boot: false);
        $module = new TargetModule;
        $app->instance(TargetModule::class, $module);
        $app->boot();
        $snapshots[] = $app->make(Modules::class)->declarations();
        expect(fn () => $module->draft?->target(alias: 'late.target', resolver: TargetAdapter::class))->toThrow(InvalidModuleException::class)
            ->and(fn () => Modules::add(app: $app, modules: TargetModule::class))->toThrow(InvalidModuleException::class);
    }
    expect($snapshots[0])->toBe($snapshots[1]);
});

it('validates target aliases and FQCN declarations without instantiating resolvers', function (string $alias, string $fqcn): void {
    expect(fn () => new ModuleRegistrar('acme.runtime')->target(alias: $alias, resolver: $fqcn))->toThrow(InvalidModuleException::class);
})->with([['bad:alias', TargetAdapter::class], ['', TargetAdapter::class], ['article', stdClass::class], ['article', TargetResolver::class], ['article', 'MissingTargetAdapter']]);

it('rejects duplicate aliases in a registrar or selected panel before publishing any registry', function (): void {
    $draft = new ModuleRegistrar('acme.runtime');
    $draft->target(alias: 'acme.target', resolver: TargetAdapter::class);
    expect(fn () => $draft->target(alias: 'acme.target', resolver: TargetAdapter::class))->toThrow(InvalidConfigurationException::class);
    $app = Targets::app(selected: ['acme.runtime', 'acme.other'], boot: false);
    expect(fn () => $app->boot())->toThrow(InvalidConfigurationException::class)
        ->and(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);
});

it('resolves only the module selected on the panel and leaves unselected resolvers untouched', function (): void {
    foreach ([['acme.runtime', TargetAdapter::class], ['acme.other', OtherTargetAdapter::class]] as [$module, $resolver]) {
        $app = Targets::app(selected: [$module]);
        $fake = Runtime::trust($app);
        $fake->surfaces->allow(viewer: Runtime::actor(), surface: Runtime::surface());
        $instance = new $resolver;
        $app->instance($resolver, $instance);
        $handles = $app->make(OperationHandles::class);
        $resolved = $handles->resolveTarget(handle: $handles->forSurface(Runtime::surface()), target: Targets::target());
        expect($resolved->read->canRead())->toBeTrue()->and($instance->calls)->toBe(1);
    }
});

it('never resolves disabled or unselected capabilities through a permissive host fallback', function (bool $enabled): void {
    $app = Targets::app(enabled: $enabled ? ['acme.runtime', 'acme.other'] : [], selected: []);
    $fake = Runtime::trust($app);
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: Runtime::surface());
    $adapter = new TargetAdapter;
    $app->instance(TargetResolver::class, $adapter);
    $handles = $app->make(OperationHandles::class);
    expect(fn () => $handles->resolveTarget(handle: $handles->forSurface(Runtime::surface()), target: Targets::target()))->toThrow(ProviderUnavailableException::class)
        ->and($adapter->calls)->toBe(0);
})->with([false, true]);

it('rejects early registry access without accidentally freezing the graph', function (): void {
    $app = Targets::app(boot: false);
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);
    $app->boot();
    expect($app->make(Modules::class)->declarations())->toHaveCount(2);
});

it('allows the same capability alias on disjoint selected panels and never resolves the other adapter', function (): void {
    $app = Targets::app(boot: false);
    PanelDefinitions::add(app: $app, providers: TargetOtherPanel::class);
    Surfaces::add(app: $app, providers: AlternateSurface::class);
    $app->instance(AlternateSurface::class, new AlternateSurface('acme.b'));
    $app->boot();
    $fake = Runtime::trust($app);
    $left = new TargetAdapter;
    $right = new OtherTargetAdapter;
    $app->instance(TargetAdapter::class, $left);
    $app->instance(OtherTargetAdapter::class, $right);
    $handles = $app->make(OperationHandles::class);
    foreach (['acme.a', 'acme.b', 'acme.a'] as $panel) {
        $surface = Runtime::surface($panel);
        $fake->surfaces->allow(viewer: Runtime::actor(), surface: $surface);
        $handle = $handles->forSurface($surface);
        $answer = $handles->resolveTarget(handle: $handle, target: Targets::target());
        expect($answer->surface->panel->value)->toBe($panel);
    }
    expect($left->calls)->toBe(2)->and($right->calls)->toBe(1);
});

it('reports duplicate aliases inside an enabled module as configuration_invalid without publishing the graph', function (): void {
    $app = Targets::app(boot: false);
    $module = new class extends TargetModule
    {
        public function register(ModuleRegistrar $registrar): void
        {
            parent::register($registrar);
            $registrar->target(alias: 'acme.target', resolver: TargetAdapter::class);
        }
    };
    $app->instance(TargetModule::class, $module);
    try {
        $app->boot();
        Assert::fail('Duplicate alias accepted');
    } catch (InvalidConfigurationException $error) {
        expect($error->problem()->value)->toBe('sockit.configuration_invalid')
            ->and($error->detail()['rule'])->toBe('MODULE_TARGET_DUPLICATE');
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);
});
