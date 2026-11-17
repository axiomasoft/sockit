<?php

declare(strict_types=1);

use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Audience\Exceptions\FloorWidensException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testing\FakeContourResolver;
use SocKit\Testing\FakeGroupMembershipResolver;
use SocKit\Testing\FakeSurfaceAudiencePolicy;

test('privacy floor allows narrowing and refuses any widening attempt', function (): void {
    $parent = new PrivacyFloor(
        allowedScopes: [Scope::Direct, Scope::Group, Scope::Contour, Scope::Platform],
        defaultScope: Scope::Platform,
        maxScope: Scope::Platform,
        minorDefaultScope: Scope::Group,
        minorMaxScope: Scope::Contour,
        contourLevels: ['team', 'tenant'],
    );

    $narrowed = $parent->narrowedBy(new PrivacyFloor(
        allowedScopes: [Scope::Direct, Scope::Group],
        defaultScope: Scope::Group,
        maxScope: Scope::Group,
        minorDefaultScope: Scope::Direct,
        minorMaxScope: Scope::Direct,
        contourLevels: [],
    ));

    expect($narrowed->maxScope)->toBe(Scope::Group)
        ->and($narrowed->allowedScopes)->toBe([Scope::Direct, Scope::Group]);

    expect(fn (): PrivacyFloor => $parent->narrowedBy(new PrivacyFloor(
        allowedScopes: [Scope::Direct, Scope::Public],
        defaultScope: Scope::Direct,
        maxScope: Scope::Public,
        minorDefaultScope: Scope::Direct,
        minorMaxScope: Scope::Direct,
        contourLevels: [],
    )))->toThrow(FloorWidensException::class);
});

test('audience evaluator supports combinators and audience gate stays fail-closed without policy or across tenants', function (): void {
    $instA = new InstallationRef('inst-a');
    $instB = new InstallationRef('inst-b');
    $alice = new ActorRef('user', 'alice');
    $bob = new ActorRef('user', 'bob');
    $team = new HostEntityRef('team', 'eng');

    $contours = (new FakeContourResolver)->set($alice, 'team', $team, ContourMembership::Live);
    $groups = new FakeGroupMembershipResolver;
    $evaluator = new AudienceEvaluator($contours, $groups);

    $surface = new SurfaceRef(new PanelId('sockit.default'), new SurfaceKindId('sockit.wall'), $team, Incarnation::initial());
    $spec = AudienceSpec::exclude(
        AudienceSpec::contour('team', $team),
        AudienceSpec::direct($bob),
    );
    $subject = new AudienceSubject('post-1', $spec, $alice, $surface, $instA, surfaceArchived: true, archiveReaders: true);

    // Without SurfaceAudiencePolicy -> Gate fails closed (deny unknown) even if evaluator allows!
    $noPolicyGate = new AudienceGate($evaluator);
    expect($noPolicyGate->read(ActorContext::person($alice, $instA), $subject)->canRead())->toBeFalse();

    // With SurfaceAudiencePolicy admitting -> archived surface downgrades to AllowReadOnly!
    $policy = new FakeSurfaceAudiencePolicy;
    $gate = new AudienceGate($evaluator, $policy);

    $aliceRead = $gate->read(ActorContext::person($alice, $instA), $subject);
    expect($aliceRead->canRead())->toBeTrue()
        ->and($aliceRead->decision)->toBe(VerdictDecision::AllowReadOnly);

    // Cross-installation denied fail-closed
    expect($gate->read(ActorContext::person($alice, $instB), $subject)->canRead())->toBeFalse();

    // Minor publish capped by minorMaxScope
    $floor = new PrivacyFloor(
        allowedScopes: [Scope::Direct, Scope::Platform],
        defaultScope: Scope::Platform,
        maxScope: Scope::Platform,
        minorDefaultScope: Scope::Direct,
        minorMaxScope: Scope::Direct,
        contourLevels: [],
    );
    $minor = ActorContext::person($bob, $instA, AgeBand::Minor);
    $minorSubject = new AudienceSubject('post-minor', AudienceSpec::platform(), $bob, $surface, $instA);

    expect($gate->publish($minor, $minorSubject, $floor)->canWrite())->toBeFalse();

    // Surface policy denial cannot be overridden to Allow by evaluator
    $policy->denyAdmission('surface.closed');
    expect($gate->read(ActorContext::person($alice, $instA), $subject))->toEqual(AudienceVerdict::deny('surface.closed'));
});
