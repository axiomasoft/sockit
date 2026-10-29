<?php

declare(strict_types=1);

use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Groups\ConsentWait;
use SocKit\Tests\Fixtures\Groups\MembershipScenario;

it('fails closed for absent, failed, unknown, stale or revoked consent without state or event', function (string $mode): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        if ($mode === 'missing') {
            $s->f->app->offsetUnset(MembershipConsent::class);
        } elseif (in_array($mode, ['denied', 'foreign', 'unknown_actor'], true)) {
            $table = $s->f->connection()->table('panel_a.membership_consent');
            if ($mode === 'denied') {
                $table->update(['allowed' => false]);
            } elseif ($mode === 'foreign') {
                $table->update(['installation' => 'other-installation']);
            } else {
                $table->delete();
            }
        } elseif (in_array($mode, ['stale', 'revoked'], true)) {
            $s->consent->afterAssess = function () use ($s, $mode): void {
                $s->f->connection()->table('panel_a.membership_consent')->increment('generation', 1, $mode === 'revoked' ? ['allowed' => false] : []);
            };
        } else {
            $s->consent->mode = $mode;
        }
        expect($s->run($g, 'join')->isSuccess())->toBeFalse()->and($s->state())->toBeNull()->and($s->revision($g)->value)->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_groups')->value('membership_generation'))->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.%'")->count())->toBe(1);
        expect(array_unique($s->consent->assessLevels))->toBeIn([[], [0]])
            ->and(array_unique($s->consent->checkLevels))->toBeIn([[], [1]]);
    } finally {
        $s->cleanup();
    }
})->with(['missing', 'error', 'unknown', 'empty', 'denied', 'foreign', 'unknown_actor', 'stale', 'revoked', 'unproven', 'recheck_error']);

it('assesses the prospective member rather than approver or inviter and never lets consent widen host permission', function (JoinPolicy $policy): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create($policy);
        $subject = new ActorRef('user', '7');
        if ($policy === JoinPolicy::Approval) {
            expect($s->run($g, 'join')->isSuccess())->toBeTrue();
            $s->actAs('8');
            $action = 'approve';
            $s->authority->denied = ['groups.approve'];
            expect($s->run($g, $action, $subject)->isSuccess())->toBeFalse();
            $s->authority->denied = [];
        } else {
            $s->actAs('8');
            expect($s->run($g, 'invite', $subject)->isSuccess())->toBeTrue()->and($s->consent->subjects)->toBe([]);
            $s->actAs('7');
            $action = 'join';
        }
        $s->f->connection()->table('panel_a.membership_consent')->where('subject', $subject->toWire())->update(['allowed' => false]);
        expect($s->run($g, $action, $action === 'approve' ? $subject : null)->isSuccess())->toBeFalse()
            ->and($s->state())->toBe($policy === JoinPolicy::Approval ? 'pending' : 'invited');
        $s->f->connection()->table('panel_a.membership_consent')->where('subject', $subject->toWire())->update(['allowed' => true]);
        expect($s->run($g, $action, $action === 'approve' ? $subject : null)->isSuccess())->toBeTrue()->and($s->state())->toBe('active');
        $last = $s->consent->subjects[array_key_last($s->consent->subjects) ?? throw new RuntimeException('No consent assessment')];
        expect($last)->toBe(['executor' => new ActorRef('user', $policy === JoinPolicy::Approval ? '8' : '7')->toWire(), 'subject' => $subject->toWire(), 'transition' => $action])
            ->and(array_unique($s->consent->assessLevels))->toBe([0])->and(array_unique($s->consent->checkLevels))->toBe([1]);
        $s->actAs('7');
        $before = count($s->consent->subjects);
        $s->consent->mode = 'error';
        $s->f->app->offsetUnset(MembershipConsent::class);
        expect($s->run($g, 'leave')->isSuccess())->toBeTrue()->and($s->state())->toBe('left')->and(count($s->consent->subjects))->toBe($before);
    } finally {
        $s->cleanup();
    }
})->with([JoinPolicy::Approval, JoinPolicy::Invite]);

it('rolls member, aggregate revision, generation and receipt back when durable outbox recording fails', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        $s->f->connection()->statement("ALTER TABLE panel_a.driver_a_outbox ADD CONSTRAINT fail_activation CHECK (envelope::jsonb->>'event_type' <> 'groups.membership_activated')");
        $input = new MembershipCommand($g, 'join');
        $command = $s->command($input, new Revision(1));
        expect($s->execute($input, $command)->isSuccess())->toBeFalse()->and($s->state())->toBeNull()->and($s->revision($g)->value)->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_groups')->value('membership_generation'))->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.%'")->count())->toBe(1);
        $receipt = $s->f->groups->lookup($s->f->handle, $command->namespace, $command->key);
        expect($receipt?->isSuccess() ?? false)->toBeFalse();
        $s->f->connection()->statement('ALTER TABLE panel_a.driver_a_outbox DROP CONSTRAINT fail_activation');
        expect($s->run($g, 'join')->isSuccess())->toBeTrue();
        $row = $s->f->connection()->table('panel_a.a_group_members')->first();
        expect($row?->consent_token)->toBeString()->and($row?->consent_generation)->toBeNull();
        $events = $s->f->connection()->table('panel_a.a_outbox')->pluck('envelope')->all();
        expect(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain('consent_token', 'fixture.subject_consent', 'consent_generation');
    } finally {
        $s->cleanup();
    }
});

it('rechecks consent after an observed PostgreSQL group lock wait', function (bool $revoke): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        $result = ConsentWait::revoke($s, $g, $revoke);
        expect($result->isSuccess())->toBeFalse()->and($s->state())->toBeNull()->and($s->revision($g)->value)->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_groups')->value('membership_generation'))->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.membership_%'")->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with([false, true]);

it('cannot widen an unproven kernel authority fence with allowed membership consent', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        $s->f->fence->unproven = true;
        expect($s->run($g, 'join')->isSuccess())->toBeFalse()->and($s->state())->toBeNull()->and($s->revision($g)->value)->toBe(1)
            ->and($s->consent->checkLevels)->toBe([]);
    } finally {
        $s->cleanup();
    }
});
