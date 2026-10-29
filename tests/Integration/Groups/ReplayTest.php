<?php

declare(strict_types=1);

use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Groups\ContentScenario;
use SocKit\Tests\Fixtures\Groups\MembershipScenario;

it('replays a historical activation without resurrecting membership and enforces current access', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        $input = new MembershipCommand($g, 'join');
        $command = $s->command($input, new Revision(1), 'membership.replay.0001');
        $first = $s->execute($input, $command);
        expect($first->isSuccess())->toBeTrue()->and($s->run($g, 'leave')->isSuccess())->toBeTrue();
        expect($s->execute($input, $command)->toWire())->toBe($first->toWire())
            ->and($s->state())->toBe('left')->and($s->revision($g)->value)->toBe(3)
            ->and($s->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' = 'groups.membership_activated'")->count())->toBe(1);
        $s->f->connection()->table('panel_a.envelope_authority')->update(['allowed' => false]);
        expect($s->execute($input, $command)->isSuccess())->toBeFalse()
            ->and($s->f->groups->lookup($s->f->handle, $command->namespace, $command->key)?->isSuccess())->toBeFalse()
            ->and($s->state())->toBe('left');
    } finally {
        $s->cleanup();
    }
});

it('requires current policy and consent to consume an invitation after reopening', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Invite);
        $s->actAs('8');
        expect($s->run($g, 'invite', new ActorRef('user', '7'))->isSuccess())->toBeTrue();
        $s->policy($g, JoinPolicy::Closed);
        $s->actAs('7');
        expect($s->run($g, 'join')->isSuccess())->toBeFalse()->and($s->state())->toBe('invited');
        $s->policy($g, JoinPolicy::Invite);
        $s->f->connection()->table('panel_a.membership_consent')->update(['allowed' => false]);
        expect($s->run($g, 'join')->isSuccess())->toBeFalse()->and($s->state())->toBe('invited');
        $s->f->connection()->table('panel_a.membership_consent')->update(['allowed' => true]);
        expect($s->run($g, 'join')->isSuccess())->toBeTrue()->and($s->state())->toBe('active');
    } finally {
        $s->cleanup();
    }
});

it('rejects a mismatched full actor reference even when the bounded digest matches', function (): void {
    $s = new MembershipScenario;
    try {
        $g = $s->create(JoinPolicy::Auto);
        expect($s->run($g, 'join')->isSuccess())->toBeTrue();
        $actor = new ActorRef('user', '7');
        $row = $s->f->connection()->table('panel_a.a_group_members')->first();
        expect($row?->actor_digest)->toBe(hash('sha256', $actor->toWire()));
        $s->f->connection()->table('panel_a.a_group_members')->update(['actor_ref' => new ActorRef('user', '8')->toWire()]);
        expect($s->run($g, 'leave')->isSuccess())->toBeFalse()->and($s->revision($g)->value)->toBe(2)
            ->and($s->f->connection()->table('panel_a.a_group_members')->value('state'))->toBe('active');
    } finally {
        $s->cleanup();
    }
});

it('denies protected post and receipt lookup after membership and host access revocation', function (): void {
    $s = new ContentScenario;
    try {
        $post = $s->post();
        $command = $s->command($post);
        $created = $s->posts->create($s->handle, $post, $command);
        expect($created->isPublished())->toBeTrue();
        $resource = $created->operation->resource ?? throw new RuntimeException('Missing post');
        expect($s->posts->read($s->handle, $resource)->body)->toBe('Group post')
            ->and($s->posts->lookup($s->handle, $command->namespace, $command->key)?->isPublished())->toBeTrue();
        expect($s->membership->run($s->group, 'leave')->isSuccess())->toBeTrue();
        $s->f->fake->surfaces->deny($s->handle->actor, $s->handle->surface);
        expect($s->posts->lookup($s->handle, $command->namespace, $command->key)?->operation->resource)->toBeNull()
            ->and($s->posts->create($s->handle, $post, $command)->operation->isSuccess())->toBeFalse();
        expect(fn () => $s->posts->read($s->handle, $resource))->toThrow(Exception::class);
        expect($s->membership->state())->toBe('left')->and($s->f->connection()->table('panel_a.a_posts')->count())->toBe(1);
    } finally {
        $s->cleanup();
    }
});
