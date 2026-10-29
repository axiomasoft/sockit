<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\ActorContextMismatchException;
use SocKit\Actors\Exceptions\UnresolvedActorException;
use SocKit\Actors\SingleInstallationTenantResolver;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Testing\FakeActorContextResolver;
use SocKit\Testing\FakeTenantResolver;

it('resolves current trusted context on every operation without caching', function (): void {
    $resolver = new FakeActorContextResolver;
    $contexts = new ActorContexts(resolver: $resolver, tenants: new FakeTenantResolver(new InstallationRef('app')));
    $first = ActorContext::anonymous(installation: new InstallationRef('app'), provenance: ContextProvenance::Http);
    $second = new ActorContext(actor: new ActorRef(type: 'user', id: '2'), kind: ActorKind::Person, installation: new InstallationRef('app'), ageBand: AgeBand::Minor, provenance: ContextProvenance::Direct);

    $resolver->actingAs($first);
    expect($contexts->current())->toBe($first);
    $resolver->actingAs($second);
    expect($contexts->current())->toBe($second);
});

it('fails closed when no principal or worker registration exists', function (bool $worker): void {
    $contexts = new ActorContexts(resolver: new FakeActorContextResolver, tenants: new FakeTenantResolver(new InstallationRef('app')));

    try {
        $worker ? $contexts->forWorker(new ActorRef(type: 'user', id: 'missing')) : $contexts->current();
        Assert::fail('Unresolved actor was accepted');
    } catch (UnresolvedActorException $exception) {
        expect($exception->problem())->toBe(ProblemCode::AuthenticationRequired);
    }
})->with([false, true]);

it('rejects a different installation for current and worker resolution', function (bool $worker): void {
    $actor = new ActorRef(type: 'user', id: '1');
    $context = new ActorContext(actor: $actor, kind: ActorKind::Person, installation: new InstallationRef('other'), ageBand: AgeBand::Adult, provenance: ContextProvenance::Direct);
    $resolver = new FakeActorContextResolver;
    $resolver->actingAs($context)->register($context);
    $contexts = new ActorContexts(resolver: $resolver, tenants: new FakeTenantResolver(new InstallationRef('app')));

    try {
        $worker ? $contexts->forWorker($actor) : $contexts->current();
        Assert::fail('Cross-installation context was accepted');
    } catch (ActorContextMismatchException $exception) {
        expect($exception->problem())->toBe(ProblemCode::ActorContextInvalid);
    }
})->with([false, true]);

it('re-resolves current worker facts with worker provenance independently of the request actor', function (): void {
    $actor = new ActorRef(type: 'user', id: '1');
    $resolver = new FakeActorContextResolver;
    $resolver->actingAs(ActorContext::anonymous(installation: new InstallationRef('app'), provenance: ContextProvenance::Http));
    $contexts = new ActorContexts(resolver: $resolver, tenants: new FakeTenantResolver(new InstallationRef('app')));
    $resolver->register(new ActorContext(actor: $actor, kind: ActorKind::Person, installation: new InstallationRef('app'), ageBand: AgeBand::Adult, provenance: ContextProvenance::Http, generation: '1'));
    expect($contexts->forWorker($actor)->generation)->toBe('1');

    $resolver->register(new ActorContext(actor: $actor, kind: ActorKind::Service, installation: new InstallationRef('app'), ageBand: AgeBand::Unknown, provenance: ContextProvenance::Direct, onBehalfOf: new ActorRef(type: 'school', id: '7'), generation: '2'));
    $resolved = $contexts->forWorker(new ActorRef(type: 'user', id: '1'));

    expect($resolved->provenance)->toBe(ContextProvenance::Worker)
        ->and($resolved->ageBand)->toBe(AgeBand::Unknown)
        ->and($resolved->kind)->toBe(ActorKind::Service)
        ->and($resolved->onBehalfOf?->toWire())->toBe('school:7')
        ->and($resolved->generation)->toBe('2')
        ->and($contexts->current()->kind)->toBe(ActorKind::Anonymous);
});

it('rejects worker resolver results for another principal or provenance', function (bool $wrongActor): void {
    $resolver = new readonly class($wrongActor) implements ActorContextResolver
    {
        public function __construct(private bool $wrongActor) {}

        public function current(): ActorContext
        {
            throw new LogicException('Worker resolution must not use current');
        }

        public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
        {
            expect($provenance)->toBe(ContextProvenance::Worker);

            return new ActorContext(
                actor: $this->wrongActor ? new ActorRef(type: 'user', id: 'spoofed') : $actor,
                kind: ActorKind::Person,
                installation: new InstallationRef('app'),
                ageBand: AgeBand::Adult,
                provenance: $this->wrongActor ? $provenance : ContextProvenance::Http,
            );
        }
    };
    $contexts = new ActorContexts(resolver: $resolver, tenants: new FakeTenantResolver(new InstallationRef('app')));

    expect(fn () => $contexts->forWorker(new ActorRef(type: 'user', id: '1')))->toThrow(ActorContextMismatchException::class);
})->with([false, true]);

it('provides default installation only through the explicit standalone resolver', function (): void {
    $resolver = new SingleInstallationTenantResolver;

    expect($resolver->current()->equals(new InstallationRef('default')))->toBeTrue();
});

it('does not register an anonymous context for worker resolution', function (): void {
    $resolver = new FakeActorContextResolver;

    expect(fn () => $resolver->register(ActorContext::anonymous(installation: new InstallationRef('app'), provenance: ContextProvenance::Direct)))
        ->toThrow(UnresolvedActorException::class);
});

it('does not substitute the current principal for an unregistered worker actor', function (): void {
    $resolver = new FakeActorContextResolver;
    $resolver->actingAs(new ActorContext(actor: new ActorRef(type: 'user', id: '1'), kind: ActorKind::Person, installation: new InstallationRef('app'), ageBand: AgeBand::Adult, provenance: ContextProvenance::Http));
    $contexts = new ActorContexts(resolver: $resolver, tenants: new FakeTenantResolver(new InstallationRef('app')));

    expect(fn () => $contexts->forWorker(new ActorRef(type: 'user', id: '1')))->toThrow(UnresolvedActorException::class);
});

it('also rejects an anonymous context from another installation', function (): void {
    $resolver = new FakeActorContextResolver;
    $resolver->actingAs(ActorContext::anonymous(installation: new InstallationRef('other'), provenance: ContextProvenance::Http));
    $contexts = new ActorContexts(resolver: $resolver, tenants: new FakeTenantResolver(new InstallationRef('app')));

    expect(fn () => $contexts->current())->toThrow(ActorContextMismatchException::class);
});
