<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Internal\GroupIdentity;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\SurfaceInstances;
use SocKit\Tests\Fixtures\Groups\ContentScenario;
use SocKit\Tests\Fixtures\Groups\Rows;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;

it('allows only active current members, with no creator, author, invitation or pending shortcut', function (string $state): void {
    $s = new ContentScenario(active: false);
    try {
        if ($state === 'pending') {
            $s->membership->policy($s->group, JoinPolicy::Approval);
            expect($s->membership->run($s->group, 'join')->isSuccess())->toBeTrue();
        } elseif ($state === 'invited') {
            $s->membership->policy($s->group, JoinPolicy::Invite);
            expect($s->membership->run($s->group, 'invite', new ActorRef('user', '7'))->isSuccess())->toBeTrue();
        } elseif ($state !== 'absent') {
            expect($s->membership->run($s->group, 'join')->isSuccess())->toBeTrue();
            if ($state === 'left') {
                expect($s->membership->run($s->group, 'leave')->isSuccess())->toBeTrue();
            }
        }
        $subject = $s->subject();
        $scalar = $s->gate->read($s->handle->actor, $subject);
        expect($scalar->canRead())->toBe($state === 'active')
            ->and($s->gate->readMany($s->handle->actor, [$subject])['content'])->toEqual($scalar)
            ->and($s->gate->publish($s->handle->actor, $subject, $s->handle->definition->effectiveFloor)->canWrite())->toBe($state === 'active');
    } finally {
        $s->cleanup();
    }
})->with(['absent', 'pending', 'invited', 'left', 'active']);

it('selects the panel per subject for identical group IDs and never shares membership across worker contexts', function (): void {
    $s = new ContentScenario;
    try {
        $actor = $s->handle->actor;
        $other = $s->neighbor();
        $s->f->fake->surfaces->allow($actor, $other);
        $row = Rows::group(new GroupIdentity($actor->installation, $other));
        $row['id'] = $s->group->id;
        $s->f->connection('b')->table('panel_b.b_groups')->insert($row);
        // No member in B. Even with identical actor/group IDs only A may read.
        $subjects = [$s->subject('a'), $s->subject('b', $other), $s->subject('a-again')];
        $verdicts = $s->gate->readMany($actor, $subjects);
        expect(array_keys($verdicts))->toBe(['a', 'b', 'a-again'])
            ->and(array_map(static fn (AudienceVerdict $v): bool => $v->canRead(), $verdicts))->toBe(['a' => true, 'b' => false, 'a-again' => true]);
        $member = Rows::member($s->group->id, $actor->actor);
        $member['state'] = 'active';
        $member['consent_token'] = 'fixture.neighbor.consent';
        $s->f->connection('b')->table('panel_b.b_group_members')->insert($member);
        expect($s->gate->read($actor, $subjects[1])->canRead())->toBeTrue();
        $s->f->connection('b')->table('panel_b.b_group_members')->update(['state' => 'left', 'revision' => 2]);
        $s->f->connection('b')->table('panel_b.b_groups')->update(['membership_generation' => 2, 'revision' => 2]);
        expect($s->gate->read($actor, $subjects[1])->canRead())->toBeFalse()->and($s->gate->read($actor, $subjects[0])->canRead())->toBeTrue();
        $queries = $s->f->db->queries;
        expect(array_any($queries, static fn (array $q): bool => str_contains($q['sql'], 'b_groups') && $q['connection'] === 'owner_b'))->toBeTrue();
        $foreign = RuntimeScenario::actor(installation: 'other-installation');
        expect($s->gate->read($foreign, $s->subject())->canRead())->toBeFalse();
        $s->f->fake->actors->actingAs($foreign);
        $s->f->fake->surfaces->allow($foreign, $s->handle->surface)->allow($foreign, $s->f->handle->surface);
        $foreignSubject = new AudienceSubject('foreign', $s->audience(), $foreign->actor, $s->handle->surface, $foreign->installation);
        expect($s->gate->read($foreign, $foreignSubject)->canRead())->toBeFalse();
        $s->f->fake->actors->actingAs($actor);
        expect($s->gate->read($actor, $s->subject())->canRead())->toBeTrue();
    } finally {
        $s->cleanup();
    }
});

it('intersects current management and content admission, parent archive and host target lifecycle', function (string $mode): void {
    $s = new ContentScenario;
    try {
        $parent = $s->f->handle->surface;
        $actor = $s->handle->actor;
        if ($mode === 'parent_readonly') {
            $s->f->fake->surfaces->allowReadOnly($actor, $parent);
        } elseif ($mode === 'parent_denied') {
            $s->f->fake->surfaces->deny($actor, $parent);
        } elseif ($mode === 'content_denied') {
            $s->f->fake->surfaces->deny($actor, $s->handle->surface);
        } elseif ($mode === 'parent_archive') {
            $surfaces = $s->f->app->make(SurfaceInstances::class);
            $revision = $surfaces->get($s->f->handle)->revision;
            $command = new CommandEnvelope(new CommandKey('parent.archive.0001'),
                new CommandNamespace($actor->installation, $parent->panel, 'host.client', 'sockit.surfaces.archive'),
                $surfaces->digest($s->f->handle, 'archive', $revision), $revision);
            expect($surfaces->archive($s->f->handle, $command)->isSuccess())->toBeTrue();
        } else {
            $s->f->connection()->table('panel_a.envelope_targets')->where('id', '42')->update($mode === 'host_readonly' ? ['lifecycle' => 'read_only'] : ['visible' => false]);
        }
        $verdict = $s->gate->read($actor, $s->subject());
        expect($verdict->canRead())->toBe(in_array($mode, ['parent_readonly', 'parent_archive', 'host_readonly'], true))
            ->and($verdict->canWrite())->toBeFalse()
            ->and($s->gate->publish($actor, $s->subject(), $s->handle->definition->effectiveFloor)->canWrite())->toBeFalse();
        $post = $s->post();
        expect($s->posts->create($s->handle, $post, $s->command($post))->operation->isSuccess())->toBeFalse();
    } finally {
        $s->cleanup();
    }
})->with(['parent_readonly', 'parent_denied', 'content_denied', 'parent_archive', 'host_readonly', 'host_hidden']);

it('denies unrelated group and unproven containment, including broader host publication without an early fence', function (): void {
    $s = new ContentScenario;
    try {
        $other = AudienceSpec::group(new GroupRef('019a1ba0-0000-7000-8000-000000000099'));
        foreach ([$other, $s->audience()] as $spec) {
            $s->f->fake->surfaces->containment($s->handle->actor, $s->handle->surface, $spec, AuthorizationDecision::unknown('fixture.unproven'));
            expect($s->gate->publish($s->handle->actor, $s->subject(spec: $spec), $s->handle->definition->effectiveFloor)->canWrite())->toBeFalse();
        }
        $parent = $s->subject('parent', $s->f->handle->surface);
        $s->f->fake->surfaces->containment($s->handle->actor, $parent->surface, $s->audience(), AuthorizationDecision::allow('fixture.host_allows'));
        expect($s->gate->read($s->handle->actor, $parent)->canRead())->toBeTrue()
            ->and($s->gate->publish($s->handle->actor, $parent, $s->f->handle->definition->effectiveFloor)->canWrite())->toBeFalse();
        $bad = new SurfaceRef($parent->surface->panel, $parent->surface->kind, $parent->surface->host, new Incarnation(2));
        $s->f->fake->surfaces->allow($s->handle->actor, $bad);
        $s->f->connection()->table('panel_a.a_groups')->update(['surface_incarnation' => 2]);
        expect($s->gate->read($s->handle->actor, $s->subject())->canRead())->toBeFalse();
    } finally {
        $s->cleanup();
    }
});
