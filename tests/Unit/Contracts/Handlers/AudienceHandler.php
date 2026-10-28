<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Testing\FakeContourResolver;
use SocKit\Testing\FakeGroupMembershipResolver;
use SocKit\Testing\FakeSurfaceAudiencePolicy;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class AudienceHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = Scenario::object($fixture['input']);
        $viewer = Scenario::actor(Scenario::object($input['viewer']));
        $contours = new FakeContourResolver($viewer->installation);
        $groups = new FakeGroupMembershipResolver($viewer->installation);
        $policy = new FakeSurfaceAudiencePolicy;
        foreach (Scenario::entries($input['contours']) as $entry) {
            $row = Scenario::object($entry);
            $actor = ActorRef::fromWire(Scenario::text($row['actor']));
            $level = Scenario::text($row['level']);
            $contour = HostEntityRef::fromWire(Scenario::text($row['contour']));
            match ($row['membership']) {
                'live' => $contours->live(actor: $actor, level: $level, contour: $contour),
                'read_only' => $contours->readOnly(actor: $actor, level: $level, contour: $contour),
                'archived' => $contours->archived(actor: $actor, level: $level, contour: $contour),
                'none' => $contours->revoke(actor: $actor, level: $level, contour: $contour),
                default => throw new \RuntimeException('Unknown fixture membership'),
            };
        }
        foreach (Scenario::entries($input['groups']) as $entry) {
            $row = Scenario::object($entry);
            $groups->member(actor: ActorRef::fromWire(Scenario::text($row['actor'])), group: new GroupRef(Scenario::text($row['group'])));
        }
        if (Scenario::flag($input['resolver_throws'])) {
            $contours->failWith(new \Error('Host facts unavailable'));
        }
        $wireSubjects = isset($input['subjects']) ? Scenario::entries($input['subjects']) : [$input['subject']];
        $subjects = [];
        foreach ($wireSubjects as $wire) {
            $row = Scenario::object($wire);
            $subject = Scenario::subject($row);
            $subjects[] = $subject;
            match ($row['admission']) {
                'allow' => $policy->allow(viewer: $viewer, surface: $subject->surface),
                'read_only' => $policy->allowReadOnly(viewer: $viewer, surface: $subject->surface),
                'deny' => $policy->deny(viewer: $viewer, surface: $subject->surface, reason: 'audience.denied.not_in_contour'),
                'missing' => null,
                default => throw new \RuntimeException('Unknown fixture admission'),
            };
            if (isset($row['containment'])) {
                $policy->containment(actor: $viewer, surface: $subject->surface, proposed: $subject->spec, decision: match ($row['containment']) {
                    'allow' => AuthorizationDecision::allow('host.contained'),
                    'deny' => AuthorizationDecision::deny('host.outside'),
                    'unknown' => AuthorizationDecision::unknown('host.unproven'),
                    default => throw new \RuntimeException('Unknown fixture containment'),
                });
            }
        }
        $gate = new AudienceGate(resolver: new AudienceEvaluator(contours: $contours, groups: $groups), surfacePolicy: $policy);
        $expected = Scenario::object($fixture['expect']);
        if (($input['operation'] ?? 'read') === 'batch') {
            if (isset($expected['problem'])) {
                try {
                    $gate->readMany(viewer: $viewer, subjects: $subjects);
                    throw new \RuntimeException('Duplicate key accepted');
                } catch (InvalidPayloadException $exception) {
                    expect($exception->problem()->value)->toBe($expected['problem']);
                }

                return;
            }
            $batch = $gate->readMany(viewer: $viewer, subjects: $subjects);
            expect(array_keys($batch))->toBe(array_map(fn (AudienceSubject $s): string => $s->key, $subjects));
            foreach ($subjects as $subject) {
                $this->verdict(actual: $batch[$subject->key], expected: Scenario::object($expected[$subject->key]));
                expect($batch[$subject->key])->toEqual($gate->read(viewer: $viewer, subject: $subject));
            }
        } else {
            $actual = ($input['operation'] ?? 'read') === 'publish'
                ? $gate->publish(author: $viewer, subject: $subjects[0], floor: Scenario::floor(Scenario::object($input['floor'])))
                : $gate->read(viewer: $viewer, subject: $subjects[0]);
            $this->verdict(actual: $actual, expected: $expected);
        }
    }

    /** @param array<string, mixed> $expected */
    private function verdict(AudienceVerdict $actual, array $expected): void
    {
        expect($actual->decision->value)->toBe($expected['decision'])->and($actual->reason)->toBe($expected['reason']);
    }
}
