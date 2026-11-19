<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\PanelDefinitions;
use SocKit\SocKitServiceProvider;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Exceptions\FrozenDefinitionException;
use SocKit\Surfaces\Exceptions\InvalidSurfaceDefinitionException;
use SocKit\Surfaces\Exceptions\UnknownSurfaceKindException;
use SocKit\Surfaces\Surfaces;
use SocKit\Tests\Fixtures\Surfaces\ConfiguredSurface;
use SocKit\Tests\Fixtures\Surfaces\EdufyClassFeedSurface;
use SocKit\Tests\Fixtures\Surfaces\PanelServiceProvider;
use SocKit\Tests\Fixtures\Surfaces\Scenario;
use SocKit\Tests\Fixtures\Surfaces\SocialServiceProvider;
use SocKit\Tests\Fixtures\Surfaces\SurfaceServiceProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario as Wire;

/** @param list<class-string> $values
 * @return list<list<class-string>> */
function surfacePermutations(array $values): array
{
    if ($values === []) {
        return [[]];
    }
    $result = [];
    foreach ($values as $index => $value) {
        $rest = $values;
        unset($rest[$index]);
        foreach (surfacePermutations(array_values($rest)) as $tail) {
            $result[] = [$value, ...$tail];
        }
    }

    return $result;
}

it('compiles identical snapshots in all 24 module panel surface core provider orders', function (): void {
    $expected = null;
    foreach (surfacePermutations([SocKitServiceProvider::class, SocialServiceProvider::class, PanelServiceProvider::class, SurfaceServiceProvider::class]) as $order) {
        $app = new Application;
        $app->instance('config', new Repository(['sockit' => ['privacy' => ['floor' => Wire::floorWire()], 'modules' => ['enabled' => ['social.posts', 'social.reactions']]]]));
        foreach ($order as $provider) {
            $app->register($provider);
        }
        expect(fn () => $app->make(SurfaceDefinitions::class))->toThrow(InvalidSurfaceDefinitionException::class);
        $app->boot();
        $registry = $app->make(SurfaceDefinitions::class);
        $actual = json_encode($registry->all(), JSON_THROW_ON_ERROR);
        $expected ??= $actual;
        expect($actual)->toBe($expected)->and($registry->all())->toHaveCount(3);
        $edufy = $registry->get(panel: new PanelId('edufy.community'), kind: new SurfaceKindId('edufy.class-feed'));
        expect($edufy->features)->toBe(['posts', 'reactions'])->and($edufy->contourLevel)->toBe('class')
            ->and($edufy->effectiveFloor->contourLevels)->toBe(['class'])->and($edufy->archiveReaders)->toBeFalse();
        $public = $registry->get(panel: new PanelId('crm.public'), kind: new SurfaceKindId('crm.article'));
        $team = $registry->get(panel: new PanelId('crm.team'), kind: new SurfaceKindId('crm.article'));
        expect($public->hostType)->toBe($team->hostType)->and($public->settings)->toBe(['display_limit' => 10])
            ->and($team->settings)->toBe(['display_limit' => 20])->and($public->features)->toBe(['reactions']);
        expect(fn () => Surfaces::add($app, EdufyClassFeedSurface::class))->toThrow(InvalidSurfaceDefinitionException::class);
    }
});

it('requires panel identity for lookup and never falls back', function (): void {
    $app = Scenario::app(['surfaces' => [[]]]);
    $app->boot();
    $registry = $app->make(SurfaceDefinitions::class);
    expect($registry->has(panel: new PanelId('crm.public'), kind: new SurfaceKindId('crm.article')))->toBeTrue()
        ->and($registry->has(panel: new PanelId('crm.team'), kind: new SurfaceKindId('crm.article')))->toBeFalse();
    try {
        $registry->get(panel: new PanelId('crm.team'), kind: new SurfaceKindId('crm.article'));
        Assert::fail('Missing surface accepted');
    } catch (UnknownSurfaceKindException $error) {
        expect($error->problem()->value)->toBe('sockit.surface_not_found')->and($error->detail()['panel'])->toBe('crm.team');
    }
    $copy = $registry->all();
    unset($copy['crm.public/crm.article']);
    expect($registry->all())->toHaveCount(1);
});

it('aggregates unused provider errors and leaves every registry unavailable after boot failure', function (): void {
    $app = Scenario::app(['surfaces' => [['host' => 'foreign', 'features' => ['posts', 'unknown']], ['panel' => 'unknown.panel'], ['reject_graph' => true, 'kind' => 'crm.second']]]);
    try {
        $app->boot();
        Assert::fail('Invalid unused graph accepted');
    } catch (InvalidSurfaceDefinitionException $error) {
        expect($error->problem()->value)->toBe('sockit.configuration_invalid')->and($error->errors)->toHaveCount(5);
        $text = implode("\n", $error->errors);
        foreach (['SURFACE_HOST_MISMATCH', 'SURFACE_FEATURE_DISABLED', 'SURFACE_PANEL_UNKNOWN', 'SURFACE_POLICY_INVALID'] as $code) {
            expect($text)->toContain($code);
        }
        foreach ($error->errors as $diagnostic) {
            expect($diagnostic)->toMatch('/^[A-Z_]+ \[.*\] panel=.* key=.+$/');
        }
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class)
        ->and(fn () => $app->make(SurfaceDefinitions::class))->toThrow(InvalidSurfaceDefinitionException::class)
        ->and(fn () => Surfaces::add($app, ConfiguredSurface::class))->toThrow(InvalidSurfaceDefinitionException::class);
});

it('hides all private drafts even during provider validation', function (): void {
    $app = Scenario::app(['surfaces' => [['early_resolve' => true]]]);
    $app->boot();
    expect($app->make(SurfaceDefinitions::class)->all())->toHaveCount(1);
});

it('collects surfaces from globally enabled module declarations only', function (bool $enabled): void {
    $app = new Application;
    $app->instance('config', new Repository(['sockit' => ['privacy' => ['floor' => Wire::floorWire()],
        'modules' => ['enabled' => $enabled ? ['social.posts', 'social.reactions', 'surface.bundle'] : ['social.posts', 'social.reactions']]]]));
    $app->register(SocKitServiceProvider::class);
    $app->register(PanelServiceProvider::class);
    $app->register(SocialServiceProvider::class);
    $app->boot();
    expect($app->make(SurfaceDefinitions::class)->all())->toHaveCount($enabled ? 1 : 0);
    expect($app->make(Modules::class)->declarations()['surface.bundle']['surfaces'] ?? [])->toBe($enabled ? [EdufyClassFeedSurface::class] : []);
})->with([false, true]);

it('rejects invalid registered surface FQCNs as surface configuration diagnostics', function (): void {
    $app = Scenario::app();
    Surfaces::add($app, stdClass::class, 'NoSuchSurface');
    try {
        $app->boot();
        Assert::fail('Invalid provider accepted');
    } catch (InvalidSurfaceDefinitionException $error) {
        expect($error->errors)->toHaveCount(2)->and($error->getMessage())->toContain('SURFACE_PROVIDER_INVALID');
    }
});

it('freezes every setter and materialize after a successful snapshot', function (): void {
    $app = Scenario::app(['surfaces' => [[]]]);
    $app->boot();
    $builder = $app->make(ConfiguredSurface::class)->builder;
    assert($builder !== null);
    $floor = Wire::floor(Wire::floorWire());
    $mutations = [fn () => $builder->panel(new PanelId('crm.team')), fn () => $builder->kind('crm.changed'), fn () => $builder->host('task'),
        fn () => $builder->label('late'), fn () => $builder->contour('class'), fn () => $builder->floor($floor),
        fn () => $builder->features(), fn () => $builder->archiveReaders(), fn () => $builder->setting(key: 'late', value: true), fn () => $builder->materialize($floor)];
    foreach ($mutations as $mutation) {
        expect($mutation)->toThrow(FrozenDefinitionException::class);
    }
    $definition = $app->make(SurfaceDefinitions::class)->get(panel: new PanelId('crm.public'), kind: new SurfaceKindId('crm.article'));
    expect(fn () => new ReflectionProperty($definition, 'features')->setValue($definition, []))->toThrow(Error::class);
});

it('deep copies referenced arrays in the immutable definition including the floor', function (): void {
    $scope = Scope::Direct;
    $level = 'class';
    $feature = 'reactions';
    $setting = 10;
    $floor = new PrivacyFloor(allowedScopes: [&$scope], defaultScope: Scope::Direct, maxScope: Scope::Direct,
        minorDefaultScope: Scope::Direct, minorMaxScope: Scope::Direct, contourLevels: [&$level]);
    $definition = new SurfaceDefinition(panel: new PanelId('crm.public'), kind: new SurfaceKindId('crm.article'), hostType: 'article', label: '',
        contourLevel: 'class', effectiveFloor: $floor, features: [&$feature], archiveReaders: false, settings: ['display_limit' => &$setting], provider: ConfiguredSurface::class);
    $scope = Scope::Public;
    $level = 'school';
    $feature = 'posts';
    $setting = 999;
    expect($definition->effectiveFloor->allowedScopes)->toBe([Scope::Direct])->and($definition->effectiveFloor->contourLevels)->toBe(['class'])
        ->and($definition->features)->toBe(['reactions'])->and($definition->settings)->toBe(['display_limit' => 10]);
});

it('freezes retained invalid builders and redacts arbitrary provider failures', function (): void {
    $app = Scenario::app(['surfaces' => [['throw' => true], ['kind' => 'INVALID']]]);
    try {
        $app->boot();
        Assert::fail('Invalid declaration accepted');
    } catch (InvalidSurfaceDefinitionException $error) {
        expect($error->errors)->toHaveCount(2)->and($error->getMessage())->not->toContain('secret provider value');
    }
    $builder = $app->make(ConfiguredSurface::class)->builder;
    assert($builder !== null);
    expect(fn () => $builder->label('late'))->toThrow(FrozenDefinitionException::class);
});

it('fails closed when a configured provider binding has the wrong runtime implementation', function (): void {
    $app = Scenario::app(['surfaces' => [[]]]);
    $app->instance(ConfiguredSurface::class, new stdClass);
    try {
        $app->boot();
        Assert::fail('Invalid runtime binding accepted');
    } catch (InvalidSurfaceDefinitionException $error) {
        expect($error->errors)->toHaveCount(1)->and($error->getMessage())->toContain('SURFACE_PROVIDER_INVALID');
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class);
});

it('checks configured provider FQCN contracts and detects config module declaration collisions', function (): void {
    $invalid = new Application;
    $invalid->instance('config', new Repository(['sockit' => ['surfaces' => [stdClass::class]]]));
    $invalid->register(SocKitServiceProvider::class);
    expect(fn () => $invalid->boot())->toThrow(InvalidConfigurationException::class);
    $app = new Application;
    $app->instance('config', new Repository(['sockit' => ['surfaces' => [EdufyClassFeedSurface::class], 'privacy' => ['floor' => Wire::floorWire()],
        'modules' => ['enabled' => ['social.posts', 'social.reactions', 'surface.bundle']]]]));
    $app->register(SocKitServiceProvider::class);
    $app->register(PanelServiceProvider::class);
    $app->register(SocialServiceProvider::class);
    try {
        $app->boot();
        Assert::fail('Duplicate module/config declaration accepted');
    } catch (InvalidSurfaceDefinitionException $error) {
        expect($error->getMessage())->toContain('SURFACE_KIND_DUPLICATE');
    }
});
