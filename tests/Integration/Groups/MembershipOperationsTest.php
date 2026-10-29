<?php

declare(strict_types=1);

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Groups\MembershipScenario;
use SocKit\Tests\Integration\Support\PgTarget;

it('commits each policy flow with aggregate CAS, durable states, generations and exact guarded replay', function (JoinPolicy $policy): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create($policy);
        $f = $s->f;
        expect($s->state())->toBeNull(); // Neither creation nor moderator grants membership.
        $join = new MembershipCommand($g, 'join');
        $first = $s->command($join, new Revision(1), 'membership.first.0001');
        if ($policy === JoinPolicy::Closed) {
            foreach (['join', 'approve', 'invite'] as $action) {
                expect($s->run($g, $action, $action === 'join' ? null : new ActorRef('user', '7'))->isSuccess())->toBeFalse();
            }
            expect($s->run($g, 'leave')->isSuccess())->toBeFalse()->and($s->revision($g)->value)->toBe(1)->and($s->state())->toBeNull();

            return;
        }
        if ($policy === JoinPolicy::Invite) {
            expect($s->execute($join, $first)->isSuccess())->toBeFalse();
            $s->actAs('8');
            expect($s->run($g, 'invite', new ActorRef('user', '7'))->outcome)->toBe(OperationOutcome::Confirmed)
                ->and($s->state())->toBe('invited')->and($s->state('8'))->toBeNull();
            expect($s->run($g, 'invite', new ActorRef('user', '7'))->isSuccess())->toBeFalse();
            $s->actAs('7');
            $first = $s->command($join, $s->revision($g), 'membership.accept.0001');
        }
        $result = $s->execute($join, $first);
        expect($result->outcome)->toBe(OperationOutcome::Confirmed)
            ->and($s->state())->toBe($policy === JoinPolicy::Approval ? 'pending' : 'active');
        expect($s->execute($join, $first)->receiptId)->toBe($result->receiptId)
            ->and($f->groups->lookup($f->handle, $first->namespace, $first->key)?->receiptId)->toBe($result->receiptId);
        expect($s->run($g, 'join')->isSuccess())->toBeFalse();
        if ($policy === JoinPolicy::Approval) {
            $s->actAs('8');
            expect($s->run($g, 'approve', new ActorRef('user', '7'))->isSuccess())->toBeTrue()->and($s->state())->toBe('active')->and($s->state('8'))->toBeNull();
            expect($s->run($g, 'approve', new ActorRef('user', '7'))->isSuccess())->toBeFalse();
            $s->actAs('7');
        }
        $beforeLeave = $s->revision($g)->value;
        $stale = $s->command(new MembershipCommand($g, 'leave'), new Revision(1));
        expect($s->execute(new MembershipCommand($g, 'leave'), $stale)->problem)->toBe(ProblemCode::RevisionConflict);
        expect($s->run($g, 'leave')->isSuccess())->toBeTrue()->and($s->state())->toBe('left');
        $afterLeave = $s->revision($g)->value;
        expect($afterLeave)->toBe($beforeLeave + 1)->and($s->run($g, 'leave')->isSuccess())->toBeFalse()
            ->and($s->revision($g)->value)->toBe($afterLeave);
        $row = $f->connection()->table('panel_a.a_groups')->first();
        expect($row?->membership_generation)->toBe($afterLeave)
            ->and($f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.membership_%'")->count())->toBe($afterLeave - 1)
            ->and(PgTarget::query($f->db->decoy, 'SELECT value FROM public.p44_sentinel')->fetchColumn())->toBe('decoy-sentinel');
        // A new join after left follows the current policy; invite needs a new invitation.
        expect($s->run($g, 'join')->isSuccess())->toBe($policy !== JoinPolicy::Invite);
        $f->connection()->table('panel_a.envelope_authority')->update(['allowed' => false]);
        expect($s->execute($join, $first)->isSuccess())->toBeFalse()
            ->and($f->groups->lookup($f->handle, $first->namespace, $first->key)?->isSuccess())->toBeFalse();
    } finally {
        $s->cleanup();
    }
})->with(JoinPolicy::cases());

it('requires cancellation of obsolete pending or invited states and preserves active members on policy changes', function (JoinPolicy $initial): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create($initial);
        $action = $initial === JoinPolicy::Approval ? 'join' : 'invite';
        expect($s->run($g, $action, $action === 'invite' ? new ActorRef('user', '7') : null)->isSuccess())->toBeTrue();
        foreach ([JoinPolicy::Closed, JoinPolicy::Auto] as $next) {
            $s->policy($g, $next);
            expect($s->run($g, 'join')->isSuccess())->toBeFalse()
                ->and($s->run($g, 'approve', new ActorRef('user', '7'))->isSuccess())->toBeFalse()
                ->and($s->run($g, 'invite', new ActorRef('user', '7'))->isSuccess())->toBeFalse();
        }
        expect($s->run($g, 'leave')->isSuccess())->toBeTrue()->and($s->run($g, 'join')->isSuccess())->toBeTrue()->and($s->state())->toBe('active');
        $s->policy($g, JoinPolicy::Closed);
        expect($s->state())->toBe('active')->and($s->run($g, 'leave')->isSuccess())->toBeTrue();
    } finally {
        $s->cleanup();
    }
})->with([JoinPolicy::Approval, JoinPolicy::Invite]);

it('binds recipient into the digest, rejects facade confusion, and keeps ordinary hard guards on leave', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Invite);
        $input = new MembershipCommand($g, 'invite', new ActorRef('user', '8'));
        $command = $s->command($input, new Revision(1), 'membership.recipient.0001');
        expect($s->execute($input, $command)->isSuccess())->toBeTrue();
        $changed = new MembershipCommand($g, 'invite', new ActorRef('user', '7'));
        expect($s->execute($changed, $s->command($changed, new Revision(1), $command->key->value))->problem)->toBe(ProblemCode::IdempotencyConflict)
            ->and($s->f->groups->join($s->f->handle, $input, $command)->problem)->toBe(ProblemCode::PayloadInvalid);
        $s->actAs('8');
        $s->authority->denied = ['groups.join'];
        expect($s->run($g, 'join')->isSuccess())->toBeFalse()->and($s->state('8'))->toBe('invited');
        $s->authority->denied = ['groups.leave'];
        expect($s->run($g, 'leave')->isSuccess())->toBeFalse();
        $s->authority->denied = [];
        $s->f->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
        expect($s->run($g, 'leave')->isSuccess())->toBeFalse();
        $s->f->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'live']);
        expect($s->run($g, 'leave')->isSuccess())->toBeTrue();
    } finally {
        $s->cleanup();
    }
});
