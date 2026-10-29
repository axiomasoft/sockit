<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use SocKit\Actors\ActorContexts;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\PanelDefinitions;
use SocKit\SocKitServiceProvider;
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
