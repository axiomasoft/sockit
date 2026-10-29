<?php

declare(strict_types=1);

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testing\FakeAudienceResolver;
use SocKit\Testing\FakeContourResolver;
use SocKit\Testing\FakeGroupMembershipResolver;
use SocKit\Testing\FakeSurfaceAudiencePolicy;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('enforces installation and unknown boundaries before invoking a custom resolver', function (): void {
    $viewer = Scenario::viewer();
    $wire = Scenario::subjectWire();
    $resolver = (new FakeAudienceResolver)->verdict(key: 'p1', verdict: AudienceVerdict::allow('audience.allowed.public'));
    $policy = new FakeSurfaceAudiencePolicy;
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: $policy);
    $wire['installation'] = 'i2';
    $subject = Scenario::subject($wire);
    $policy->allow(viewer: $viewer, surface: $subject->surface);
    expect($gate->read(viewer: $viewer, subject: $subject)->reason)->toBe('audience.denied.other_installation')
        ->and($gate->publish(author: $viewer, subject: $subject, floor: Scenario::floor(Scenario::floorWire()))->reason)->toBe('audience.denied.other_installation')
        ->and($gate->readMany(viewer: $viewer, subjects: [$subject])['p1']->reason)->toBe('audience.denied.other_installation')
        ->and($resolver->calls())->toBe([]);

    $wire['installation'] = 'i1';
    $wire['surface'] = [...Scenario::object($wire['surface']), 'panel' => 'host.unregistered'];
    $subject = Scenario::subject($wire);
    expect($gate->read(viewer: $viewer, subject: $subject)->reason)->toBe('audience.unknown')
        ->and($resolver->calls())->toBe([])
        ->and(new AudienceGate($resolver)->read(viewer: $viewer, subject: $subject)->canRead())->toBeFalse();
});

it('does not reuse admission across panel target incarnation installation or actor', function (): void {
    $viewer = Scenario::viewer();
    $wire = Scenario::subjectWire();
    $subject = Scenario::subject($wire);
    $resolver = (new FakeAudienceResolver)->verdict(key: 'p1', verdict: AudienceVerdict::allow('audience.allowed.public'));
    $policy = (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface);
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: $policy);
    expect($gate->read(viewer: $viewer, subject: $subject)->canRead())->toBeTrue();
    foreach (['panel' => 'edufy.other', 'host' => 'edufy.class:43', 'incarnation' => 2, 'kind' => 'edufy.other'] as $key => $value) {
        $changed = $wire;
        $changed['surface'] = [...Scenario::object($changed['surface']), $key => $value];
        expect($gate->read(viewer: $viewer, subject: Scenario::subject($changed))->canRead())->toBeFalse();
    }
    $other = Scenario::actor(['actor' => 'user:2', 'kind' => 'person', 'installation' => 'i1', 'age_band' => 'adult']);
    expect($gate->read(viewer: $other, subject: $subject)->canRead())->toBeFalse();
    $policy->deny(viewer: $viewer, surface: $subject->surface, reason: 'audience.denied.not_in_contour');
    expect($gate->read(viewer: $viewer, subject: $subject)->canRead())->toBeFalse();
});

it('intersects admission content and archive for every audience scope', function (string $scope, bool $archive): void {
    $viewer = Scenario::viewer();
    $wire = Scenario::subjectWire();
    $wire['spec'] = match ($scope) {
        'direct' => ['version' => '1.0', 'scope' => $scope, 'actors' => ['user:1']],
        'group' => ['version' => '1.0', 'scope' => $scope, 'group' => 'club'],
        'contour' => $wire['spec'],
        default => ['version' => '1.0', 'scope' => $scope],
    };
    $wire['surface_archived'] = $archive;
    $subject = Scenario::subject($wire);
    $policy = new FakeSurfaceAudiencePolicy;
    $policy->allowReadOnly(viewer: $viewer, surface: $subject->surface);
    $resolver = (new FakeAudienceResolver)->verdict(key: 'p1', verdict: AudienceVerdict::allow('audience.allowed.public'));
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: $policy);
    expect($gate->read(viewer: $viewer, subject: $subject)->decision)->toBe(VerdictDecision::AllowReadOnly)
        ->and($gate->publish(author: $viewer, subject: $subject, floor: Scenario::floor(Scenario::floorWire()))->canWrite())->toBeFalse();
    $policy->allow(viewer: $viewer, surface: $subject->surface);
    expect($gate->read(viewer: $viewer, subject: $subject)->canWrite())->toBe(! $archive);
    $resolver->verdict(key: 'p1', verdict: AudienceVerdict::deny('audience.denied.not_addressed'));
    expect($gate->read(viewer: $viewer, subject: $subject)->canRead())->toBeFalse();
})->with(['direct', 'group', 'contour', 'platform', 'public'])->with([false, true]);

it('preserves content read-only verdicts and metadata through the gate', function (): void {
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $content = new AudienceVerdict(decision: VerdictDecision::AllowReadOnly, reason: 'audience.allowed.observer', policyVersion: 'v2', expiresAt: new DateTimeImmutable('+1 minute'));
    $resolver = (new FakeAudienceResolver)->verdict(key: 'p1', verdict: $content);
    $policy = (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface)->containment(actor: $viewer, surface: $subject->surface, proposed: $subject->spec, decision: AuthorizationDecision::allow('host.contained'));
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: $policy);
    expect($gate->read(viewer: $viewer, subject: $subject))->toEqual($content)
        ->and($gate->publish(author: $viewer, subject: $subject, floor: Scenario::floor(Scenario::floorWire()))->canWrite())->toBeFalse();
});

it('keeps mandatory floor and containment checks even for an allowing custom resolver', function (): void {
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $resolver = (new FakeAudienceResolver)->verdict(key: 'p1', verdict: AudienceVerdict::allow('audience.allowed.contour_live'));
    $policy = (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface);
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: $policy);
    $floor = Scenario::floor(Scenario::floorWire());
    expect($gate->publish(author: $viewer, subject: $subject, floor: $floor)->reason)->toBe('audience.denied.outside_surface');
    $policy->containment(actor: $viewer, surface: $subject->surface, proposed: $subject->spec, decision: AuthorizationDecision::unknown('host.unproven'));
    expect($gate->publish(author: $viewer, subject: $subject, floor: $floor)->reason)->toBe('audience.denied.boundary_unproven');
    $policy->containment(actor: $viewer, surface: $subject->surface, proposed: $subject->spec, decision: AuthorizationDecision::allow('host.contained'));
    expect($gate->publish(author: $viewer, subject: $subject, floor: $floor)->canWrite())->toBeTrue();
    $narrow = new PrivacyFloor(allowedScopes: [Scope::Direct], defaultScope: Scope::Direct, maxScope: Scope::Direct, minorDefaultScope: Scope::Direct, minorMaxScope: Scope::Direct, contourLevels: []);
    expect($gate->publish(author: $viewer, subject: $subject, floor: $narrow)->reason)->toBe('audience.denied.scope_not_allowed');
});

it('maps Throwable from content admission and containment to resolver_error', function (string $source): void {
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $resolver = new FakeAudienceResolver;
    $policy = (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface);
    if ($source === 'content') {
        $resolver->failWith(new Error('Secret host error'));
        $policy->containment(actor: $viewer, surface: $subject->surface, proposed: $subject->spec, decision: AuthorizationDecision::allow('host.contained'));
    } elseif ($source === 'admission') {
        $policy->failWith(new Error('Secret boundary error'));
    } else {
        $policy = new class implements SurfaceAudiencePolicy
        {
            public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
            {
                return AudienceVerdict::allow('audience.allowed.platform');
            }

            public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
            {
                throw new Error('Secret containment error');
            }
        };
    }
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: $policy);
    expect($gate->publish(author: $viewer, subject: $subject, floor: Scenario::floor(Scenario::floorWire()))->reason)->toBe('audience.resolver_error');
    if ($source !== 'containment') {
        expect($gate->read(viewer: $viewer, subject: $subject)->reason)->toBe('audience.resolver_error')
            ->and($gate->readMany(viewer: $viewer, subjects: [$subject])['p1']->reason)->toBe('audience.resolver_error');
    }
})->with(['content', 'admission', 'containment']);

it('rejects missing or divergent batch output instead of widening scalar access', function (bool $missing): void {
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $resolver = (new FakeAudienceResolver)->verdict(key: 'p1', verdict: $missing ? AudienceVerdict::allow('audience.allowed.contour_live') : AudienceVerdict::deny('audience.denied.not_in_contour'));
    $resolver->batch($missing ? [] : ['p1' => AudienceVerdict::allow('audience.allowed.contour_live')]);
    $policy = (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface);
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: $policy);
    expect($gate->readMany(viewer: $viewer, subjects: [$subject])['p1']->reason)->toBe('audience.unknown')
        ->and($resolver->calls()[0]['operation'])->toBe('batch');
})->with([false, true]);

it('validates duplicate batch keys before any host calls', function (): void {
    $subject = Scenario::subject(Scenario::subjectWire());
    $resolver = new FakeAudienceResolver;
    $gate = new AudienceGate($resolver);
    expect(fn () => $gate->readMany(viewer: Scenario::viewer(), subjects: [$subject, $subject]))->toThrow(InvalidPayloadException::class);
    expect($resolver->calls())->toBe([])->and($gate->readMany(viewer: Scenario::viewer(), subjects: []))->toBe([]);
});

it('supports scalar-only resolvers with identical batch outcomes', function (): void {
    $resolver = new class implements AudienceResolver
    {
        public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
        {
            return AudienceVerdict::allow('audience.allowed.public');
        }

        public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
        {
            throw new LogicException('Not part of this test');
        }
    };
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $gate = new AudienceGate(resolver: $resolver, surfacePolicy: (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface));
    expect($gate->readMany(viewer: $viewer, subjects: [$subject]))->toEqual(['p1' => $gate->read(viewer: $viewer, subject: $subject)]);
});

it('rechecks membership and admission after revoke without caching', function (): void {
    $viewer = Scenario::viewer();
    $subject = Scenario::subject(Scenario::subjectWire());
    $contours = new FakeContourResolver($viewer->installation);
    $ref = ActorRef::fromWire('user:1');
    $host = HostEntityRef::fromWire('edufy.class:42');
    $contours->live(actor: $ref, level: 'class', contour: $host);
    $policy = (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface);
    $gate = new AudienceGate(resolver: new AudienceEvaluator(contours: $contours, groups: new FakeGroupMembershipResolver($viewer->installation)), surfacePolicy: $policy);
    expect($gate->read(viewer: $viewer, subject: $subject)->canWrite())->toBeTrue();
    $contours->revoke(actor: $ref, level: 'class', contour: $host);
    expect($gate->read(viewer: $viewer, subject: $subject)->reason)->toBe('audience.denied.not_in_contour');
    $contours->readOnly(actor: $ref, level: 'class', contour: $host);
    expect($gate->read(viewer: $viewer, subject: $subject)->decision)->toBe(VerdictDecision::AllowReadOnly);
});

it('keeps mixed successful and failing batch subjects equivalent to scalar', function (): void {
    $viewer = Scenario::viewer();
    $contour = Scenario::subject(Scenario::subjectWire());
    $wire = Scenario::subjectWire('public');
    $wire['spec'] = ['version' => '1.0', 'scope' => 'public'];
    $public = Scenario::subject($wire);
    $policy = (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $contour->surface);
    $contours = new FakeContourResolver($viewer->installation)->failWith(new Error('Unavailable facts'));
    $gate = new AudienceGate(resolver: new AudienceEvaluator(contours: $contours, groups: new FakeGroupMembershipResolver($viewer->installation)), surfacePolicy: $policy);
    $batch = $gate->readMany(viewer: $viewer, subjects: [$contour, $public]);
    expect($batch['p1'])->toEqual($gate->read(viewer: $viewer, subject: $contour))
        ->and($batch['public'])->toEqual($gate->read(viewer: $viewer, subject: $public))
        ->and($batch['public']->canRead())->toBeTrue();
});
