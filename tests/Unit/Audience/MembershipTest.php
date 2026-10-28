<?php

declare(strict_types=1);

use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Testing\FakeContourResolver;
use SocKit\Testing\FakeGroupMembershipResolver;
use SocKit\Testing\FakeSurfaceAudiencePolicy;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('uses current group membership independently of author identity and group rank', function (): void {
    $viewer = Scenario::viewer();
    $wire = Scenario::subjectWire();
    $wire['spec'] = ['version' => '1.0', 'scope' => 'group', 'group' => 'club'];
    $wire['author'] = 'user:1';
    $subject = Scenario::subject($wire);
    $groups = new FakeGroupMembershipResolver($viewer->installation);
    $policy = (new FakeSurfaceAudiencePolicy)->allow(viewer: $viewer, surface: $subject->surface)->containment(actor: $viewer, surface: $subject->surface, proposed: $subject->spec, decision: AuthorizationDecision::allow('host.contained'));
    $gate = new AudienceGate(resolver: new AudienceEvaluator(contours: new FakeContourResolver($viewer->installation), groups: $groups), surfacePolicy: $policy);
    $floor = Scenario::floor(Scenario::floorWire());
    expect($gate->read(viewer: $viewer, subject: $subject)->reason)->toBe('audience.denied.not_in_group')
        ->and($gate->publish(author: $viewer, subject: $subject, floor: $floor)->reason)->toBe('audience.denied.author_outside_audience');
    $groups->member(actor: ActorRef::fromWire('user:1'), group: new GroupRef('club'));
    expect($gate->publish(author: $viewer, subject: $subject, floor: $floor)->canWrite())->toBeTrue();
    $groups->revoke(actor: ActorRef::fromWire('user:1'), group: new GroupRef('club'));
    expect($gate->read(viewer: $viewer, subject: $subject)->canRead())->toBeFalse();
    $groups->failWith(new Error('Group facts unavailable'));
    expect($gate->read(viewer: $viewer, subject: $subject)->reason)->toBe('audience.resolver_error');
});

it('keeps fake contour and group facts within their configured installation', function (): void {
    $viewer = Scenario::viewer();
    $other = Scenario::actor(['actor' => 'user:1', 'kind' => 'person', 'installation' => 'i2', 'age_band' => 'adult']);
    $contours = new FakeContourResolver($viewer->installation);
    $groups = new FakeGroupMembershipResolver($viewer->installation);
    $ref = ActorRef::fromWire('user:1');
    $host = HostEntityRef::fromWire('edufy.class:42');
    $group = new GroupRef('club');
    $contours->live(actor: $ref, level: 'class', contour: $host);
    $groups->member(actor: $ref, group: $group);
    expect($contours->membership(actor: $viewer, level: 'class', contour: $host))->toBe(ContourMembership::Live)
        ->and($contours->membership(actor: $other, level: 'class', contour: $host))->toBe(ContourMembership::None)
        ->and($groups->isMember(actor: $other, group: $group))->toBeFalse();
    $school = HostEntityRef::fromWire('edufy.school:7');
    $contours->enclose(child: $host, level: 'school', parent: $school);
    expect($contours->enclosing(contour: $host, level: 'school'))->toBe($school)
        ->and($contours->enclosing(contour: $host, level: 'region'))->toBeNull();
});
