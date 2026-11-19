<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Packman\Config\Contracts\SettingsLookup;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use SocKit\Actors\ActorContexts;
use SocKit\Configuration\SocKitConfig;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Modules\Modules;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\PanelDefinitions;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Groups\Selection;
use SocKit\Tests\Fixtures\Modules\PackmanModulePlugin;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;

it('boots the provider', function (): void {
    expect(config('sockit'))->toBeArray()
        ->and(app()->getProviders(SocKitServiceProvider::class))->not->toBeEmpty();
});

it('boots declared panels with a fail-on-connect spy and never resolves operation state', function (): void {
    $application = RuntimeScenario::app(boot: false);
    $connections = Mockery::mock(DatabaseManager::class);
    $connections->shouldNotReceive('connection');
    $application->instance(abstract: 'db', instance: $connections);
    $application->instance(abstract: ConnectionResolverInterface::class, instance: $connections);
    $application->boot();
    expect($application->make(PanelDefinitions::class)->all())->toHaveCount(3)
        ->and($application->resolved(OperationHandles::class))->toBeFalse()
        ->and($application->resolved(OwnerStorage::class))->toBeFalse()
        ->and($application->resolved(ActorContexts::class))->toBeFalse();
});

it('registers every SocKit wire problem and preserves accepted operation outcomes', function (): void {
    app()->register(PackmanServiceProvider::class);
    $registry = app(ProblemRegistry::class);
    foreach (ProblemCode::cases() as $code) {
        $problem = $registry->fromWire($code->value);
        expect($problem->code)->toBe($code)
            ->and($problem->retryable)->toBe($code->spec()->retryable);
    }

    expect(ProblemCode::ContentHeld->spec()->httpStatus)->toBe(202)
        ->and($registry->get(ProblemCode::ContentHeld)->defaultStatus)->toBe(409)
        ->and(ProblemCode::ExternalOutcomeUnknown->spec()->httpStatus)->toBe(202)
        ->and($registry->get(ProblemCode::ExternalOutcomeUnknown)->defaultStatus)->toBe(503);
});

it('compiles Packman settings through the same configuration factory as the runtime', function (): void {
    app()->register(PackmanServiceProvider::class);
    expect(app(SettingsLookup::class)->get(SocKitConfig::class))->toEqual(app(SocKitConfig::class));
});

it('offers Packman plugin modules to the SocKit compiler', function (): void {
    $app = Selection::app(enabled: ['demo.module'], extra: ['packman' => ['plugins' => [PackmanModulePlugin::class]]],
        providers: [PackmanServiceProvider::class, PluginsServiceProvider::class, SocKitServiceProvider::class]);
    $app->boot();

    expect($app->make(Modules::class)->settings()['demo.module'])->toBe(['limit' => 10]);
});
