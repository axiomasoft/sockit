<?php

declare(strict_types=1);

use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Enums\SafetyDecision;
use SocKit\Reactions\Data\ReactionQuery;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\PostLifecycle;
use SocKit\Tests\Fixtures\Social\PostReactionRace;
use SocKit\Tests\Fixtures\Social\PostReactionScenario;
use SocKit\Tests\Fixtures\Social\SafetyAdapter;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('serializes both actual PG orders of reaction versus hide delete and same scope shrink', function (string $first, string $action): void {
    $f = new PostReactionScenario;
    $s = $f->s;
    try {
        $ref = $f->create();
        [$a, $b] = PostReactionRace::run($f, $ref, $first, $action);
        if ($first === 'reaction') {
            expect($a->isSuccess())->toBeTrue()->and($b->isSuccess())->toBeTrue();
        } else {
            expect($a->isSuccess())->toBeTrue();
            PostLifecycle::denied($b);
        }
        $expected = $first === 'reaction' ? 1 : 0;
        $counts = $f->counts();
        expect($counts['members'])->toBe($expected)->and($counts['active'])->toBe($expected)->and($counts['count'])->toBe($expected)
            ->and($counts['outbox'])->toBe($expected)->and($counts['receipts'])->toBe($expected);
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 2, 'receipts' => 2, 'outbox' => 2]);
        $f->actor(RuntimeScenario::actor('8'));
        $target = $f->target($ref);
        $command = $f->command($target);
        PostLifecycle::denied($f->add($target, $command));
        if ($expected === 1) {
            PostLifecycle::denied($f->reactions->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key));
            PostLifecycle::denied($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key));
        }
        expect($f->reactions->summary(handle: $s->handle, target: $target)->counts)->toBeNull();
        $batch = $f->reactions->summaryMany(handle: $s->handle, queries: [new ReactionQuery('old', $target)]);
        expect($batch['old']->counts)->toBeNull()->and($batch['old']->ownKinds)->toBeNull();
        expect(fn () => $f->reactions->rebuild(handle: $s->handle, target: $target))->toThrow(SocKitException::class);
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
        expect($f->counts())->toBe($counts);
    } finally {
        $s->cleanup();
    }
})->with([
    'reaction then hide' => ['reaction', 'hide'], 'hide then reaction' => ['post', 'hide'],
    'reaction then delete' => ['reaction', 'delete'], 'delete then reaction' => ['post', 'delete'],
    'reaction then shrink' => ['reaction', 'audience'], 'shrink then reaction' => ['post', 'audience'],
]);

it('rolls back a reaction when host authority is revoked while its local lock waits', function (): void {
    $f = new PostReactionScenario;
    try {
        $ref = $f->create();
        [$revoker, $reaction] = PostReactionRace::run($f, $ref, 'post', 'revoke');
        PostLifecycle::denied($reaction);
        expect($f->counts())->toBe(['members' => 0, 'active' => 0, 'count' => 0, 'receipts' => 1, 'outbox' => 0]);
        $receipt = $f->s->connection()->table('panel_a.a_receipts')->where('namespace', 'like', '%/reactions.%')->first();
        expect($receipt?->outcome)->toBe('rejected')->and($receipt?->resource)->toBeNull()->and($receipt?->revision)->toBeNull();
        expect($f->s->counts())->toBe(['posts' => 1, 'revisions' => 1, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $f->s->cleanup();
    }
});

it('restores visibility without reviving old receipts or allowing stale incarnation and reordered event targets', function (): void {
    $f = new PostReactionScenario;
    $s = $f->s;
    try {
        $ref = $f->create();
        $f->actor(RuntimeScenario::actor('8'));
        $target = $f->target($ref);
        $command = $f->command($target);
        expect($f->add($target, $command)->isSuccess())->toBeTrue();
        $wire = Scenario::text($s->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' = 'reactions.added'")->value('envelope'));
        $oldEvent = EventEnvelope::fromWire(Scenario::object(json_decode($wire, true, flags: JSON_THROW_ON_ERROR)));
        expect($oldEvent->target)->toEqual($target)->and($oldEvent->privacyRevision->value)->toBe(1);
        $f->actor(RuntimeScenario::actor());
        expect($f->life->execute($ref, 'hide', 1)->operation->isSuccess())->toBeTrue();
        expect($f->life->execute($ref, 'restore', 2)->operation->isSuccess())->toBeTrue();
        $f->actor(RuntimeScenario::actor('8'));
        expect($f->reactions->summary(handle: $s->handle, target: $target)->counts)->toBe(['like' => 1]);
        PostLifecycle::denied($f->add($target, $command));
        PostLifecycle::denied($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key));
        // Fresh remove uses the retained membership CAS; restore never emits another add.
        $remove = $f->command(target: $target, key: 'reaction.post.remove.0001', expected: new Revision(1), action: 'remove');
        expect($f->reactions->remove(handle: $s->handle, reaction: $f->reaction($target), command: $remove)->isSuccess())->toBeTrue();
        $f->actor(RuntimeScenario::actor());
        $restore = $f->life->transition($ref, 'restore');
        $delayedRestore = $f->life->command($restore, 2, 'post.delayed.restore.0001');
        expect($f->life->execute($ref, 'delete', 3)->operation->isSuccess())->toBeTrue();
        $terminal = $f->life->row($ref);
        $before = $f->counts();
        // Deliver old target references in reverse event order: resolution always reads the owner now.
        foreach ([$f->life->event(3), $f->life->event(2), $f->life->event(1)] as $event) {
            $historical = EventEnvelope::fromWire($event);
            expect($historical->payload['post'])->toEqual($ref);
            expect(fn () => $s->app->make(OperationHandles::class)->resolveTarget(handle: $s->handle, target: $target))->toThrow(SocKitException::class);
        }
        expect($oldEvent->target)->not->toBeNull();
        expect(fn () => $s->app->make(OperationHandles::class)->resolveTarget(handle: $s->handle, target: $oldEvent->target ?? throw new RuntimeException('Target')))->toThrow(SocKitException::class);
        PostLifecycle::denied($s->posts->transition(handle: $s->handle, transition: $restore, command: $delayedRestore)->operation);
        PostLifecycle::denied($f->add($f->target($ref, incarnation: 2)));
        expect($f->life->row($ref))->toBe($terminal)->and($f->counts())->toBe($before);
        expect($terminal['visibility'])->toBe('deleted')->and($terminal['revision'])->toBe(4);
        expect($before)->toBe(['members' => 1, 'active' => 0, 'count' => 0, 'receipts' => 2, 'outbox' => 2]);
    } finally {
        $s->cleanup();
    }
});

it('preserves Held and Pending through hide restore and keeps every reaction path denied', function (string $state): void {
    $f = new PostReactionScenario;
    $s = $f->s;
    try {
        if ($state === 'held') {
            $s->app->instance(ContentSafety::class, new SafetyAdapter($s->connection(), SafetyDecision::Hold));
        }
        $ref = $f->create(publish: $state !== 'pending');
        expect($f->life->execute($ref, 'hide', 1)->operation->isSuccess())->toBeTrue();
        expect($f->life->execute($ref, 'restore', 2)->operation->isSuccess())->toBeTrue();
        expect($f->life->row($ref)['publication_state'])->toBe($state);
        PostLifecycle::denied($f->add($f->target($ref)));
        expect($f->counts())->toBe(['members' => 0, 'active' => 0, 'count' => 0, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['held', 'pending']);
