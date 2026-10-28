<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\Exceptions\ProviderUnavailableException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Operations\OperationHandles;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Testing\FakeTargetResolver;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario as Runtime;
use SocKit\Tests\Fixtures\Runtime\TargetAdapter;
use SocKit\Tests\Fixtures\Runtime\TargetScenario as Targets;

it('swaps an explicitly configured host adapter lazily and keeps unavailable configuration closed', function (): void {
    $app = Targets::app();
    $fake = Runtime::trust($app);
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: Runtime::surface());
    // Restore production configured binding instead of the testing override.
    $app->forgetInstance(TargetResolver::class);
    $handles = $app->make(OperationHandles::class);
    $handle = $handles->forSurface(Runtime::surface());
    expect($app->make(TargetResolver::class))->toBeInstanceOf(TargetAdapter::class)
        ->and($handles->resolveTarget(handle: $handle, target: Targets::target('article'))->read->canRead())->toBeTrue()
        ->and($handles->resolveTarget(handle: $handle, target: Targets::target('product'))->read->canRead())->toBeTrue();
    $app->forgetScopedInstances();
    $first = $app->make(TargetResolver::class);
    $app->forgetScopedInstances();
    expect($app->make(TargetResolver::class))->not->toBe($first);
    $empty = Targets::app(fallback: null);
    expect(fn () => $empty->make(TargetResolver::class))->toThrow(ProviderUnavailableException::class);
    expect(fn () => Targets::app(fallback: stdClass::class))->toThrow(InvalidConfigurationException::class);
    expect($app->make(SocKitConfig::class)->resolvers->targets)->toBe(TargetAdapter::class);
});

it('keeps SocKitFake default deny across actors panels installations and incarnations', function (): void {
    $app = Runtime::app();
    $fake = Runtime::trust($app);
    $handles = $app->make(OperationHandles::class);
    $surface = Runtime::surface();
    $target = Targets::target('article');
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: $surface);
    expect($app->make(TargetResolver::class))->toBe($fake->targets)->toBeInstanceOf(FakeTargetResolver::class);
    $a = $handles->forSurface($surface);
    expect($handles->resolveTarget(handle: $a, target: $target)->read->canRead())->toBeFalse();
    $answer = new TargetAdapter()->resolve(actor: Runtime::actor(), surface: $surface, target: $target);
    $fake->targets->returns(actor: Runtime::actor(), resolved: $answer);
    expect($handles->resolveTarget(handle: $a, target: $target)->read->canRead())->toBeTrue()
        ->and($handles->resolveTarget(handle: $a, target: Targets::target(alias: 'article', incarnation: 2))->read->canRead())->toBeFalse();
    foreach ([Runtime::actor('99'), Runtime::actor(generation: '2')] as $actor) {
        expect($fake->targets->resolve(actor: $actor, surface: $surface, target: $target)->read->canRead())->toBeFalse();
    }
    $bSurface = Runtime::surface('acme.b');
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: $bSurface);
    expect($handles->resolveTarget(handle: $handles->forSurface($bSurface), target: $target)->read->canRead())->toBeFalse()
        ->and($fake->targets->resolve(actor: Runtime::actor(installation: 'foreign'), surface: $surface, target: $target)->read->canRead())->toBeFalse();
});

it('denies malicious allow for mismatched panel installation target or surface incarnation', function (string $field): void {
    $app = Targets::app();
    $fake = Runtime::trust($app);
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: Runtime::surface());
    $adapter = new TargetAdapter;
    $wire = $adapter->resolve(actor: Runtime::actor(), surface: Runtime::surface(), target: Targets::target())->toWire();
    match ($field) {
        'panel' => $wire['surface']['panel'] = 'acme.b',
        'installation' => $wire['installation'] = 'foreign',
        'target' => $wire['target']['host'] = 'acme.target:999',
        'incarnation' => $wire['target']['incarnation'] = 2,
        'surface' => $wire['surface']['incarnation'] = 2,
        default => throw new LogicException('Unknown fixture field'),
    };
    $adapter->answer = TargetResolution::fromWire($wire);
    $app->instance(TargetAdapter::class, $adapter);
    $handles = $app->make(OperationHandles::class);
    try {
        $handles->resolveTarget(handle: $handles->forSurface(Runtime::surface()), target: Targets::target());
        Assert::fail('Mismatched host allow accepted');
    } catch (AuthorizationDeniedException $error) {
        expect($error->problem()->value)->toBe('sockit.authorization_unknown');
    }
})->with(['panel', 'installation', 'target', 'incarnation', 'surface']);

it('checks current admission before calling a malicious host allow and observes revocation on replay', function (): void {
    $app = Targets::app();
    $fake = Runtime::trust($app);
    $adapter = new TargetAdapter;
    $app->instance(TargetAdapter::class, $adapter);
    $handles = $app->make(OperationHandles::class);
    $handle = $handles->forSurface(Runtime::surface());
    expect(fn () => $handles->resolveTarget(handle: $handle, target: Targets::target()))->toThrow(AuthorizationDeniedException::class)
        ->and($adapter->calls)->toBe(0);
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: Runtime::surface());
    expect($handles->resolveTarget(handle: $handle, target: Targets::target())->write->canWrite())->toBeTrue();
    $fake->surfaces->deny(viewer: Runtime::actor(), surface: Runtime::surface());
    expect(fn () => $handles->resolveTarget(handle: $handle, target: Targets::target()))->toThrow(AuthorizationDeniedException::class)
        ->and($adapter->calls)->toBe(1);
});

it('re-resolves every entry and restricts unknown hidden deleted read-only and expired targets', function (string $lifecycle, bool $read, bool $write): void {
    $app = Targets::app();
    $fake = Runtime::trust($app);
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: Runtime::surface());
    $adapter = new TargetAdapter;
    $app->instance(TargetAdapter::class, $adapter);
    $handles = $app->make(OperationHandles::class);
    $handle = $handles->forSurface(Runtime::surface());
    $wire = $handles->resolveTarget(handle: $handle, target: Targets::target())->toWire();
    if ($lifecycle === 'expired') {
        $wire['read']['expires_at'] = '2000-01-01T00:00:00.000000Z';
    } else {
        $wire['lifecycle'] = $lifecycle;
    }
    $adapter->answer = TargetResolution::fromWire($wire);
    $resolved = $handles->resolveTarget(handle: $handle, target: Targets::target());
    expect($resolved->read->canRead())->toBe($read)->and($resolved->write->canWrite())->toBe($write)->and($adapter->calls)->toBe(2);
})->with([['unknown', false, false], ['deleted', false, false], ['hidden', false, false], ['read_only', true, false], ['live', true, true], ['expired', false, false]]);

it('does not let a remote target or read-only admission authorize a local write', function (): void {
    $app = Targets::app();
    $fake = Runtime::trust($app);
    $fake->surfaces->allowReadOnly(viewer: Runtime::actor(), surface: Runtime::surface());
    $handles = $app->make(OperationHandles::class);
    $handle = $handles->forSurface(Runtime::surface());
    $resolved = $handles->resolveTarget(handle: $handle, target: Targets::target());
    expect($resolved->write->canWrite())->toBeFalse()
        ->and($handles->recheckTarget(handle: $handle, resolved: $resolved)->isAllowed())->toBeFalse();
});

it('maps resolver exceptions and invalid runtime bindings to fail-closed stable problems', function (): void {
    $app = Targets::app();
    $fake = Runtime::trust($app);
    $fake->surfaces->allow(viewer: Runtime::actor(), surface: Runtime::surface());
    $adapter = new TargetAdapter;
    $adapter->fail = true;
    $app->instance(TargetAdapter::class, $adapter);
    $handles = $app->make(OperationHandles::class);
    expect(fn () => $handles->resolveTarget(handle: $handles->forSurface(Runtime::surface()), target: Targets::target()))->toThrow(AuthorizationDeniedException::class);
    $app->instance(TargetAdapter::class, new stdClass);
    expect(fn () => $handles->resolveTarget(handle: $handles->forSurface(Runtime::surface()), target: Targets::target()))->toThrow(ProviderUnavailableException::class);
});

it('preserves finite admission expiry and read-only intersection in returned target visibility', function (): void {
    $app = Targets::app();
    Runtime::trust($app);
    $policy = new class implements SurfaceAudiencePolicy
    {
        public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
        {
            return new AudienceVerdict(decision: VerdictDecision::AllowReadOnly, reason: 'audience.allowed.observer', expiresAt: new DateTimeImmutable('2030-01-01T00:00:00Z'));
        }

        public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
        {
            return AuthorizationDecision::unknown('fixture.unsupported');
        }
    };
    $app->instance(SurfaceAudiencePolicy::class, $policy);
    $handles = $app->make(OperationHandles::class);
    $resolved = $handles->resolveTarget(handle: $handles->forSurface(Runtime::surface()), target: Targets::target());
    expect($resolved->read->decision)->toBe(VerdictDecision::AllowReadOnly)
        ->and($resolved->read->expiresAt?->format('Y-m-d'))->toBe('2030-01-01')
        ->and($resolved->write->canWrite())->toBeFalse();
});
