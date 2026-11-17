<?php

declare(strict_types=1);

use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
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
use SocKit\Testing\Fakes\Audience\FakeContourResolver;
use SocKit\Testing\Fakes\Audience\FakeGroupMembershipResolver;
use SocKit\Testing\Fakes\Audience\FakeSurfaceAudiencePolicy;

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

test('any_of publish checks all branches, exclude fails closed on resolver failure, and gate enforces floor after custom resolver', function (): void {
    $inst = new InstallationRef('inst-1');
    $alice = new ActorRef('user', 'alice');
    $bob = new ActorRef('user', 'bob');
    $team = new HostEntityRef('team', 'core');
    $surface = new SurfaceRef(new PanelId('sockit.default'), new SurfaceKindId('sockit.wall'), $team, Incarnation::initial());

    $directOnlyFloor = new PrivacyFloor(
        allowedScopes: [Scope::Direct],
        defaultScope: Scope::Direct,
        maxScope: Scope::Direct,
        minorDefaultScope: Scope::Direct,
        minorMaxScope: Scope::Direct,
        contourLevels: [],
    );

    $aliceCtx = ActorContext::person($alice, $inst);
    $bobCtx = ActorContext::person($bob, $inst);

    // #5: anyOf(direct($alice), public()) must be denied under direct-only floor (second branch exceeds ceiling)
    $evaluator = new AudienceEvaluator;
    $policy = new FakeSurfaceAudiencePolicy;
    $gate = new AudienceGate($evaluator, $policy);

    $mixedSpec = AudienceSpec::anyOf(AudienceSpec::direct($alice), AudienceSpec::public());
    $mixedSubject = new AudienceSubject('post-anyof', $mixedSpec, $alice, $surface, $inst);
    expect($gate->publish($aliceCtx, $mixedSubject, $directOnlyFloor)->canWrite())->toBeFalse();

    // #6: Permissive custom AudienceResolver cannot bypass core PrivacyFloor or return AllowReadOnly for publish
    $permissiveResolver = new class implements AudienceResolver
    {
        public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
        {
            return AudienceVerdict::allow();
        }

        public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
        {
            return AudienceVerdict::allow();
        }
    };
    $customGate = new AudienceGate($permissiveResolver, $policy);

    $publicSubject = new AudienceSubject('post-public', AudienceSpec::public(), $alice, $surface, $inst);
    expect($customGate->publish($aliceCtx, $publicSubject, $directOnlyFloor)->canWrite())->toBeFalse();

    $minorFloor = new PrivacyFloor(
        allowedScopes: [Scope::Direct, Scope::Group],
        defaultScope: Scope::Group,
        maxScope: Scope::Group,
        minorDefaultScope: Scope::Direct,
        minorMaxScope: Scope::Direct,
        contourLevels: [],
    );
    $minorCtx = ActorContext::person($bob, $inst, AgeBand::Minor);
    $groupSubject = new AudienceSubject('post-minor-group', AudienceSpec::group(new GroupRef('staff')), $bob, $surface, $inst);
    expect($customGate->publish($minorCtx, $groupSubject, $minorFloor)->canWrite())->toBeFalse();

    $contourFloor = new PrivacyFloor(
        allowedScopes: [Scope::Direct, Scope::Contour],
        defaultScope: Scope::Direct,
        maxScope: Scope::Contour,
        minorDefaultScope: Scope::Direct,
        minorMaxScope: Scope::Direct,
        contourLevels: ['team'],
    );
    $foreignContour = new AudienceSubject('post-bad-contour', AudienceSpec::contour('org', $team), $alice, $surface, $inst);
    expect($customGate->publish($aliceCtx, $foreignContour, $contourFloor)->canWrite())->toBeFalse();

    // AllowReadOnly returned by custom AudienceResolver::decidePublish fails closed
    $readOnlyPublishResolver = new class implements AudienceResolver
    {
        public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
        {
            return AudienceVerdict::allowReadOnly();
        }

        public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
        {
            return AudienceVerdict::allowReadOnly();
        }
    };
    $roGate = new AudienceGate($readOnlyPublishResolver, $policy);
    $directSubject = new AudienceSubject('post-direct', AudienceSpec::direct($alice), $alice, $surface, $inst);
    expect($roGate->publish($aliceCtx, $directSubject, $directOnlyFloor)->canWrite())->toBeFalse();

    // #7: exclude() fails closed if exclusion predicate lacks resolver or resolver throws, even inside anyOf()
    $excludedGroupSpec = AudienceSpec::exclude(
        AudienceSpec::platform(),
        AudienceSpec::group(new GroupRef('banned')),
    );
    $excludedSubject = new AudienceSubject('post-excl', $excludedGroupSpec, $alice, $surface, $inst);
    expect($evaluator->decideRead($bobCtx, $excludedSubject)->canRead())->toBeFalse();

    $failingGroups = (new FakeGroupMembershipResolver)->failWith(new RuntimeException('group store down'));
    $failingEvaluator = new AudienceEvaluator(groups: $failingGroups);
    expect($failingEvaluator->decideRead($bobCtx, $excludedSubject)->canRead())->toBeFalse();

    $allOfWrapped = AudienceSpec::allOf($excludedGroupSpec, AudienceSpec::direct($bob));
    $allOfSubject = new AudienceSubject('post-allof-excl', $allOfWrapped, $alice, $surface, $inst);
    expect($failingEvaluator->decideRead($bobCtx, $allOfSubject)->canRead())->toBeFalse();

    $otherAnyOf = AudienceSpec::anyOf($excludedGroupSpec, AudienceSpec::direct($alice));
    $otherSubject = new AudienceSubject('post-anyof-other', $otherAnyOf, $alice, $surface, $inst);
    expect($failingEvaluator->decideRead($bobCtx, $otherSubject)->canRead())->toBeFalse();

    // Direct grant on another branch of anyOf still permits the explicitly addressed viewer
    $bobGrantedAnyOf = AudienceSpec::anyOf($excludedGroupSpec, AudienceSpec::direct($bob));
    $bobGrantedSubject = new AudienceSubject('post-anyof-bob', $bobGrantedAnyOf, $alice, $surface, $inst);
    expect($failingEvaluator->decideRead($bobCtx, $bobGrantedSubject)->canRead())->toBeTrue();
});
