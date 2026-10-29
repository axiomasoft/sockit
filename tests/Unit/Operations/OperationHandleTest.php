<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Internal\Storage\StorageBindingException;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\Exceptions\PanelNotFoundException;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Exceptions\UnknownSurfaceKindException;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;

it('captures trusted context and immutable panel-qualified refs without IO', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $handles = $app->make(OperationHandles::class);
    $a = $handles->forSurface(Runtime::surface());
    $b = $handles->forSurface(Runtime::surface('acme.b'));
    expect($a->actor)->toBe($fake->actors->current())
        ->and($a->panel->storage->connection)->toBe('owner_a')
        ->and($a->panel->storage->tables['receipts'])->toBe('a_receipts')
        ->and($a->surface->host)->toEqual($b->surface->host)
        ->and($a->surface->equals($b->surface))->toBeFalse()
        ->and(SurfaceRef::fromWire($a->surface->toWire())->equals($a->surface))->toBeTrue();
    expect(fn () => new ReflectionProperty($a, 'actor')->setValue($a, Runtime::actor('99')))->toThrow(Error::class);
    expect(fn () => new ReflectionProperty($a->panel->storage, 'connection')->setValue($a->panel->storage, 'decoy'))->toThrow(Error::class);
    $restored = unserialize(serialize($a), ['allowed_classes' => true]);
    if (! $restored instanceof OperationHandle) {
        throw new RuntimeException('Invalid restored handle');
    }
    $handles->assertCurrent($restored);
});

it('fails closed for absent trusted resolvers', function (): void {
    expect(fn () => Runtime::app()->make(OperationHandles::class)->forSurface(Runtime::surface()))->toThrow(ProviderUnavailableException::class);
});

it('rejects unknown panel kind and host before storage', function (): void {
    $app = Runtime::app();
    Runtime::trust($app);
    $handles = $app->make(OperationHandles::class);
    expect(fn () => $handles->forSurface(Runtime::surface('acme.unknown')))->toThrow(PanelNotFoundException::class)
        ->and(fn () => $handles->forSurface(Runtime::surface(host: 'product')))->toThrow(StorageBindingException::class);
    $wire = Runtime::surface()->toWire();
    $wire['kind'] = 'acme.unknown';
    expect(fn () => $handles->forSurface(SurfaceRef::fromWire($wire)))->toThrow(UnknownSurfaceKindException::class);
});

it('requires all used kernel table mappings without inventing defaults', function (): void {
    $app = Runtime::app(complete: false);
    Runtime::trust($app);
    try {
        $app->make(OperationHandles::class)->forSurface(Runtime::surface());
        Assert::fail('Incomplete map accepted');
    } catch (InvalidConfigurationException $error) {
        expect($error->problem()->value)->toBe('sockit.configuration_invalid')->and($error->detail())->toBe(['panel' => 'acme.a', 'key' => 'tables.outbox']);
    }
});

it('rejects forged actor storage and foreign namespace snapshots', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $handles = $app->make(OperationHandles::class);
    $a = $handles->forSurface(Runtime::surface());
    $b = $handles->forSurface(Runtime::surface('acme.b'));
    $forgedActor = new OperationHandle(surface: $a->surface, actor: Runtime::actor('99'), panel: $a->panel, definition: $a->definition);
    $forgedStorage = new OperationHandle(surface: $a->surface, actor: $a->actor, panel: $b->panel, definition: $a->definition);
    expect(fn () => $handles->assertCurrent($forgedActor))->toThrow(ActorContextMismatchException::class)
        ->and(fn () => $handles->assertCurrent($forgedStorage))->toThrow(StorageBindingException::class);
    foreach ([new CommandNamespace(installation: $a->actor->installation, panel: $b->surface->panel, integration: 'acme.runtime', commandType: 'acme.write'), new CommandNamespace(installation: new InstallationRef('foreign'), panel: $a->surface->panel, integration: 'acme.runtime', commandType: 'acme.write')] as $namespace) {
        expect(fn () => $handles->assertCurrent(handle: $a, namespace: $namespace))->toThrow(StorageBindingException::class);
    }
    $fake->actors->actingAs(Runtime::actor(installation: 'foreign'));
    expect(fn () => $handles->assertCurrent($a))->toThrow(ActorContextMismatchException::class);
    $fake->actors->actingAs(Runtime::actor(generation: '2'));
    expect(fn () => $handles->assertCurrent($a))->toThrow(ActorContextMismatchException::class);
    $fake->actors->actingAs(Runtime::actor());
    $handles->assertCurrent($a);
});
