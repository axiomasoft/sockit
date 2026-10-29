<?php

declare(strict_types=1);

use SocKit\Actors\ActorContexts;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\OperationJobs;
use SocKit\Support\Data\ActorRef;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Tests\Fixtures\Groups\ConsentWait;
use SocKit\Tests\Fixtures\Groups\ContentScenario;
use SocKit\Tests\Fixtures\Groups\MembershipAuthority;
use SocKit\Tests\Fixtures\Groups\MembershipRace;
use SocKit\Tests\Fixtures\Groups\MembershipScenario;
use SocKit\Tests\Fixtures\Runtime\EnvelopeAuthority;
use SocKit\Tests\Fixtures\Runtime\EnvelopeAuthorityFence;
use SocKit\Tests\Fixtures\Runtime\EnvelopeMutation;
use SocKit\Tests\Fixtures\Runtime\EnvelopeTarget;
use SocKit\Tests\Fixtures\Runtime\WorkerScenario;
use SocKit\Tests\Integration\Support\PgTarget;

it('serializes membership claims and CAS on distinct PostgreSQL backends', function (string $mode): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        [$a, $b] = MembershipRace::run($s, $g, $mode);
        expect($a->isSuccess())->toBe($mode !== 'rollback')->and($b->isSuccess())->toBe(in_array($mode, ['duplicate', 'rollback'], true));
        if ($mode === 'duplicate') {
            expect($b->toWire())->toBe($a->toWire());
        }
        if ($mode === 'cas') {
            expect($b->problem)->toBe(ProblemCode::RevisionConflict);
        }
        expect($s->state())->toBe('active')->and($s->revision($g)->value)->toBe(2)
            ->and($s->f->connection()->table('panel_a.a_group_members')->count())->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' = 'groups.membership_activated'")->count())->toBe(1);
    } finally {
        $s->cleanup();
    }
})->with(['duplicate', 'cas', 'rollback']);

it('rejects consent changed while waiting for the group lock', function (bool $revoke): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        expect(ConsentWait::revoke($s, $g, $revoke)->isSuccess())->toBeFalse()
            ->and($s->state())->toBeNull()->and($s->revision($g)->value)->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.membership_%'")->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with([false, true]);

it('prevents stale approval from resurrecting a departed pending member', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Approval);
        expect($s->run($g, 'join')->isSuccess())->toBeTrue();
        [$leave, $approve] = MembershipRace::run($s, $g, 'cas', 'leave', 'approve', '7', '8', '-', '7');
        expect($leave->isSuccess())->toBeTrue()->and($approve->problem)->toBe(ProblemCode::RevisionConflict)
            ->and($s->state())->toBe('left')->and($s->revision($g)->value)->toBe(3);
    } finally {
        $s->cleanup();
    }
});

it('rejects a conflicting digest for the same concurrent invitation claim', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Invite);
        [$a, $b] = MembershipRace::run($s, $g, 'digest', 'invite', 'invite', '8', '8', '7', '8');
        expect($a->isSuccess())->toBeTrue()->and($b->problem)->toBe(ProblemCode::IdempotencyConflict)
            ->and($s->state())->toBe('invited')->and($s->state('8'))->toBeNull()->and($s->revision($g)->value)->toBe(2)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' = 'groups.membership_invited'")->count())->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('rejects stale leave after approval and allows an intentional fresh leave', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Approval);
        expect($s->run($g, 'join')->isSuccess())->toBeTrue();
        [$approve, $leave] = MembershipRace::run($s, $g, 'cas', 'approve', 'leave', '8', '7', '7');
        expect($approve->isSuccess())->toBeTrue()->and($leave->problem)->toBe(ProblemCode::RevisionConflict)
            ->and($s->state())->toBe('active')->and($s->run($g, 'leave')->isSuccess())->toBeTrue()->and($s->state())->toBe('left');
    } finally {
        $s->cleanup();
    }
});

it('rechecks local consent after a predecessor rolls back its receipt claim', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        [$first, $second] = MembershipRace::run($s, $g, 'consent_rollback');
        expect($first->isSuccess())->toBeFalse()->and($second->isSuccess())->toBeFalse()
            ->and($s->state())->toBeNull()->and($s->revision($g)->value)->toBe(1)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'groups.membership_%'")->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
});

it('serializes join and leave of an invited member in both orders', function (bool $leaveFirst): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Invite);
        $s->actAs('8');
        expect($s->run($g, 'invite', new ActorRef('user', '7'))->isSuccess())->toBeTrue();
        $s->actAs('7');
        [$winner, $loser] = MembershipRace::run($s, $g, 'cas', $leaveFirst ? 'leave' : 'join', $leaveFirst ? 'join' : 'leave');
        expect($winner->isSuccess())->toBeTrue()->and($loser->problem)->toBe(ProblemCode::RevisionConflict)
            ->and($s->state())->toBe($leaveFirst ? 'left' : 'active')->and($s->revision($g)->value)->toBe(3);
        if (! $leaveFirst) {
            expect($s->run($g, 'leave')->isSuccess())->toBeTrue()->and($s->state())->toBe('left');
        }
        expect($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' = 'groups.membership_activated'")->count())->toBe($leaveFirst ? 0 : 1);
    } finally {
        $s->cleanup();
    }
})->with([true, false]);

it('serializes invitation against closing policy in both orders', function (bool $policyFirst): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Invite);
        [$winner, $loser] = MembershipRace::run($s, $g, 'cas', $policyFirst ? 'policy' : 'invite', $policyFirst ? 'invite' : 'policy', '8', '8', $policyFirst ? '-' : '7', $policyFirst ? '7' : '-');
        expect($winner->isSuccess())->toBeTrue()->and($loser->problem)->toBe(ProblemCode::RevisionConflict)
            ->and($s->state())->toBe($policyFirst ? null : 'invited')->and($s->revision($g)->value)->toBe(2);
        if (! $policyFirst) {
            $s->actAs('8');
            $s->policy($g, JoinPolicy::Closed);
        }
        $s->actAs('7');
        expect($s->run($g, 'join')->isSuccess())->toBeFalse()
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' = 'groups.membership_activated'")->count())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with([true, false]);

it('keeps worker actor contexts isolated across nested jobs on the same process', function (): void {
    $s = new WorkerScenario;
    try {
        $jobs = new OperationJobs($s->a);
        $actors = $s->a->make(ActorContexts::class);
        $outer = $s->job(key: 'p55.outer.actor.7', revision: 2);
        $inner = $s->job(key: 'p55.inner.actor.8', principal: '8');
        $result = $jobs->execute(job: $outer, factory: function (OperationHandle $handle) use ($s, $inner): EnvelopeMutation {
            expect($handle->actor->actor?->id)->toBe('7')->and($s->run($inner)->isSuccess())->toBeTrue()
                ->and($handle->actor->actor?->id)->toBe('7');

            return $s->mutation();
        });
        expect($result->isSuccess())->toBeTrue()->and($s->a->make(ActorContexts::class))->toBe($actors)
            ->and($actors->current()->actor?->id)->toBe('caller')->and($s->counts()['effects'])->toBe(2);
        $rows = PgTarget::query($s->base->base->db->owner, 'SELECT DISTINCT actor_ref FROM panel_a.driver_a_receipts ORDER BY actor_ref');
        expect($rows->fetchAll(PDO::FETCH_COLUMN))->toBe(['user:7', 'user:8']);
    } finally {
        $s->cleanup();
    }
});

it('isolates equal membership keys and actors across panels while another panel row is locked', function (): void {
    $s = new ContentScenario(active: false);
    try {
        $m = $s->membership;
        $m->policy($s->group, JoinPolicy::Invite);
        $surface = $s->neighbor();
        $m->actAs('8');
        $a = $s->f->handle;
        $inputA = new MembershipCommand($s->group, 'invite', new ActorRef('user', '7'));
        $commandA = $m->command($inputA, $m->revision($s->group), 'p55.same.panel.key');
        expect($m->execute($inputA, $commandA)->isSuccess())->toBeTrue();
        $connection = $s->f->connection('b');
        $connection->statement('CREATE TABLE panel_b.other_envelope_targets (id text PRIMARY KEY, incarnation bigint NOT NULL, generation text NOT NULL, privacy bigint NOT NULL, visible boolean NOT NULL, lifecycle text NOT NULL, held boolean NOT NULL)');
        $connection->statement("INSERT INTO panel_b.other_envelope_targets VALUES ('42',1,'1',1,true,'live',false)");
        $target = new EnvelopeTarget($connection, 'panel_b');
        $authority = new EnvelopeAuthority($connection, 'panel_b');
        $s->f->app->instance(TargetResolver::class, $target);
        $s->f->app->instance(ActionAuthorizer::class, new MembershipAuthority($authority));
        $s->f->app->instance(MutationAuthorityFence::class, new EnvelopeAuthorityFence($connection, 'panel_b'));
        $s->f->handle = $s->f->app->make(OperationHandles::class)->forSurface($surface);
        $m->actAs('8');
        $b = $m->create(JoinPolicy::Invite);
        $inputB = new MembershipCommand($b, 'invite', new ActorRef('user', '7'));
        $commandB = $m->command($inputB, $m->revision($b), 'p55.same.panel.key');
        $owner = $s->f->db->owner;
        $owner->beginTransaction();
        try {
            $lock = $owner->prepare('SELECT id FROM panel_a.driver_a_groups WHERE id=? FOR UPDATE');
            $lock->execute([$s->group->id]);
            expect($lock->fetchColumn())->toBe($s->group->id);
            $connection->statement("SET statement_timeout='3s'");
            expect($m->execute($inputB, $commandB)->isSuccess())->toBeTrue();
        } finally {
            $owner->rollBack();
        }
        expect($connection->table('panel_b.b_group_members')->value('state'))->toBe('invited')
            ->and($connection->table('panel_b.b_group_members')->value('actor_ref'))->toBe('user:7')
            ->and($s->f->connection()->table('panel_a.a_group_members')->value('actor_ref'))->toBe('user:7')
            ->and($s->f->connection()->table('panel_a.a_group_members')->value('state'))->toBe('invited')
            ->and($commandA->namespace->panel->value)->toBe('acme.a')->and($commandB->namespace->panel->value)->toBe('acme.b');
        $s->f->handle = $a;
    } finally {
        $s->cleanup();
    }
});
