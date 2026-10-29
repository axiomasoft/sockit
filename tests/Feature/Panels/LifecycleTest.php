<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\Exceptions\PanelNotFoundException;
use SocKit\Panels\PanelDefinitions;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Modules\DemoProvider;
use SocKit\Tests\Fixtures\Modules\OtherProvider;
use SocKit\Tests\Fixtures\Panels\ArticlePanel;
use SocKit\Tests\Fixtures\Panels\ConfiguredPanel;
use SocKit\Tests\Fixtures\Panels\NestedModule;
use SocKit\Tests\Fixtures\Panels\PanelServiceProvider;
use SocKit\Tests\Fixtures\Panels\ProductPanel;
use SocKit\Tests\Fixtures\Panels\Scenario;

/** @param list<class-string> $values
 * @return list<list<class-string>> */
function panelPermutations(array $values): array
{
    if ($values === []) {
        return [[]];
    }
    $result = [];
    foreach ($values as $index => $value) {
        $rest = $values;
        unset($rest[$index]);
        foreach (panelPermutations(array_values($rest)) as $tail) {
            $result[] = [$value, ...$tail];
        }
    }

    return $result;
}

it('PANEL-01/04 compiles all 24 provider orders into identical independent panels', function (): void {
    $expected = null;
    foreach (panelPermutations([SocKitServiceProvider::class, DemoProvider::class, OtherProvider::class, PanelServiceProvider::class]) as $order) {
        $app = new Application;
        $app->instance('config', new Repository(['sockit' => ['modules' => ['enabled' => ['demo.module', 'other.module'], 'settings' => ['demo.module' => ['limit' => 20]]]]]));
        foreach ($order as $provider) {
            $app->register($provider);
        }
        expect(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class);
        $app->boot();
        $registry = $app->make(PanelDefinitions::class);
        $actual = json_encode($registry->all(), JSON_THROW_ON_ERROR);
        $expected ??= $actual;
        expect($actual)->toBe($expected);
        $first = $registry->get(new PanelId('host.articles'));
        $second = $registry->get(new PanelId('host.products'));
        expect($first->storage->tables['receipts'])->toBe('articles_receipts')
            ->and($second->storage->tables['receipts'])->toBe('products_receipts')
            ->and($first->moduleSettings)->toBe(['demo.module' => ['limit' => 30]])
            ->and($second->moduleSettings)->toBe(['other.module' => []])
            ->and($first->provider)->toBe(ArticlePanel::class)
            ->and($second->provider)->toBe(ProductPanel::class)
            ->and($app->make(Modules::class)->settings()['demo.module'])->toBe(['limit' => 20]);
        expect(fn () => PanelDefinitions::add($app, ArticlePanel::class))->toThrow(InvalidPanelException::class);
        $copy = $registry->all();
        unset($copy['host.articles']);
        expect($registry->has(new PanelId('host.articles')))->toBeTrue();
    }
});

it('PANEL-02 never falls back for an unknown panel', function (): void {
    $app = Scenario::app(['panels' => [['id' => 'host.articles']]]);
    $app->boot();
    $registry = $app->make(PanelDefinitions::class);
    expect($registry->has(new PanelId('unknown.panel')))->toBeFalse();
    try {
        $registry->get(new PanelId('unknown.panel'));
        Assert::fail('Unknown panel accepted');
    } catch (PanelNotFoundException $error) {
        expect($error->problem()->value)->toBe('sockit.panel_not_found');
    }
});

it('PANEL-04 rejects an unused invalid graph without publishing modules or panels', function (): void {
    $app = Scenario::app(['panels' => [['id' => 'host.articles'], ['id' => 'host.products', 'prefix' => 'second_', 'reject_graph' => true]]]);
    expect(fn () => $app->boot())->toThrow(InvalidPanelException::class)
        ->and(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class)
        ->and(fn () => PanelDefinitions::add($app, ArticlePanel::class))->toThrow(InvalidPanelException::class);
});

it('freezes retained builders and makes all nested snapshots immutable', function (): void {
    $app = Scenario::app(['panels' => [['id' => 'host.articles', 'modules' => ['demo.module']]]]);
    $app->boot();
    $builder = $app->make(ConfiguredPanel::class)->builder;
    expect($builder)->not->toBeNull();
    assert($builder !== null);
    expect(fn () => $builder->modules())->toThrow(InvalidPanelException::class);
    $panel = $app->make(PanelDefinitions::class)->get(new PanelId('host.articles'));
    expect(fn () => new ReflectionProperty($panel, 'moduleSettings')->setValue($panel, []))->toThrow(Error::class);
    expect(fn () => new ReflectionProperty($panel->storage, 'tables')->setValue($panel->storage, []))->toThrow(Error::class);
});

it('merges nested defaults/global/panel maps, replaces lists and enforces the schema ceiling', function (int $limit): void {
    $app = new Application;
    $app->instance('config', new Repository(['sockit' => ['panels' => [ConfiguredPanel::class],
        'modules' => ['enabled' => ['nested.module'], 'settings' => ['nested.module' => ['policy' => ['limit' => 20]]]]]]));
    $app->instance(ConfiguredPanel::class, new ConfiguredPanel(['id' => 'host.articles', 'modules' => ['nested.module'],
        'settings' => ['nested.module' => ['policy' => ['limit' => $limit, 'labels' => []]]]]));
    $app->register(SocKitServiceProvider::class);
    Modules::add($app, NestedModule::class);
    if ($limit > 100) {
        expect(fn () => $app->boot())->toThrow(InvalidPanelException::class)
            ->and(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);

        return;
    }
    $app->boot();
    $panel = $app->make(PanelDefinitions::class)->get(new PanelId('host.articles'));
    expect($panel->moduleSettings)->toBe(['nested.module' => ['policy' => ['limit' => 30, 'labels' => []]]])
        ->and($app->make(Modules::class)->settings())->toBe(['nested.module' => ['policy' => ['limit' => 20, 'labels' => ['a', 'b']]]]);
})->with([30, 101]);

it('rejects duplicate configured providers and invalid FQCNs', function (): void {
    $app = new Application;
    $app->instance('config', new Repository(['sockit' => ['panels' => [ArticlePanel::class, ArticlePanel::class], 'modules' => ['enabled' => ['demo.module']]]]));
    $app->register(SocKitServiceProvider::class);
    $app->register(DemoProvider::class);
    expect(fn () => $app->boot())->toThrow(InvalidPanelException::class)
        ->and(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class)
        ->and(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);
    $invalid = new Application;
    $invalid->instance('config', new Repository(['sockit' => ['panels' => [stdClass::class]]]));
    expect(fn () => $invalid->register(SocKitServiceProvider::class))->toThrow(InvalidConfigurationException::class);
});
