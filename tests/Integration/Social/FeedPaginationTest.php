<?php

declare(strict_types=1);

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Data\FeedQuery;
use SocKit\Posts\Exceptions\InvalidFeedCursorException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\FeedPolicy;
use SocKit\Tests\Fixtures\Social\FeedScenario;
use SocKit\Tests\Fixtures\Social\FeedWorker;
use SocKit\Tests\Fixtures\Social\PostLifecycle;
use SocKit\Tests\Integration\Support\PgTarget;

it('walks real PG timestamp ties exactly once with strict descending keysets and no offsets', function (): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $refs = $f->tied(7);
        $queries = [];
        $s->connection()->listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $ids = [];
        $cursor = null;
        for ($i = 0; $i < 5; $i++) {
            $page = $f->page(2, $cursor);
            $ids = [...$ids, ...FeedScenario::ids($page)];
            $cursor = $page->nextCursor;
            if ($cursor === null) {
                break;
            }
        }
        expect($cursor)->toBeNull()->and($ids)->toBe(array_column($refs, 'id'));
        expect(implode(' ', $queries))->toContain('(published_at, id) < (?, ?)');
        expect(implode(' ', $queries))->not->toContain('offset');
        $s->assertSentinels();
    } finally {
        $s->cleanup();
    }
});

it('refills denied gaps and advances empty budget pages past the last scanned candidate', function (): void {
    $f = new FeedScenario(chunk: 2, budget: 3);
    $s = $f->s;
    try {
        $refs = $f->tied(8);
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $policy->denied = array_column(array_slice($refs, 0, 5), 'id');
        $s->app->instance(AudienceGate::class, new AudienceGate($policy, $s->fake->surfaces));
        $a = $f->page();
        expect($a->items)->toBe([])->and($a->nextCursor)->not->toBeNull();
        foreach ($refs as $ref) {
            expect($a->nextCursor)->not->toContain($ref->id);
        }
        $b = $f->page(cursor: $a->nextCursor);
        expect(FeedScenario::ids($b))->toBe([$refs[5]->id])->and($b->nextCursor)->not->toBeNull();
        $c = $f->page(cursor: $b->nextCursor);
        expect(FeedScenario::ids($c))->toBe([$refs[6]->id, $refs[7]->id]);
        $d = $f->page(cursor: $c->nextCursor);
        expect($d->items)->toBe([])->and($d->nextCursor)->toBeNull();
        expect(array_sum(array_map(count(...), $policy->batches)))->toBe(8);
    } finally {
        $s->cleanup();
    }
});

it('does not drop an unprocessed fetched tail when the page fills or denied candidates require refill', function (): void {
    $f = new FeedScenario(chunk: 100);
    $s = $f->s;
    try {
        $refs = $f->tied(5);
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $policy->denied = [$refs[1]->id];
        $s->app->instance(AudienceGate::class, new AudienceGate($policy, $s->fake->surfaces));
        $a = $f->page();
        $b = $f->page(cursor: $a->nextCursor);
        expect([...FeedScenario::ids($a), ...FeedScenario::ids($b)])->toBe([$refs[0]->id, $refs[2]->id, $refs[3]->id, $refs[4]->id]);
    } finally {
        $s->cleanup();
    }
});

it('keeps first publication position through concurrent insert edit hide restore and delete', function (): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $refs = $f->tied(5);
        $a = $f->page(1);
        expect(FeedScenario::ids($a))->toBe([$refs[0]->id]);
        $new = $f->add('new above cursor');
        $edit = $s->post(body: 'edited', resource: $refs[1]);
        expect($s->posts->edit($s->handle, $edit, $s->command($edit, new Revision(1), 'feed.edit.request.0001'))->operation->isSuccess())->toBeTrue();
        $life = new PostLifecycle($s);
        expect($life->execute($refs[2], 'hide', 1)->operation->isSuccess())->toBeTrue();
        expect($life->execute($refs[2], 'restore', 2)->operation->isSuccess())->toBeTrue();
        expect($life->execute($refs[3], 'delete', 1)->operation->isSuccess())->toBeTrue();
        $b = $f->page(10, $a->nextCursor);
        expect(FeedScenario::ids($b))->toBe([$refs[1]->id, $refs[2]->id, $refs[4]->id]);
        expect($b->items[0]->body)->toBe('edited')->and($b->items[1]->publishedAt?->format('Y-m-d H:i:s.u'))->toBe('2026-10-29 01:00:00.123456');
        expect(FeedScenario::ids($f->page(1)))->toBe([$new->id]);
    } finally {
        $s->cleanup();
    }
});

it('rechecks a shortlist changed by a second PG session before hydration and refills it', function (): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $refs = $f->tied(3);
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $policy->afterBatch = function () use ($s, $refs): void {
            $other = PgTarget::fromEnvironment()->connect();
            $row = $s->connection()->selectOne('select pg_backend_pid() as pid');
            if (! $row instanceof stdClass || ! isset($row->pid) || ! is_int($row->pid)) {
                throw new RuntimeException('Missing PG backend PID');
            }
            expect(PgTarget::query($other, 'select pg_backend_pid()')->fetchColumn())->not->toBe($row->pid);
            $stmt = $other->prepare("UPDATE panel_a.driver_a_posts SET visibility='deleted', revision=revision+1 WHERE id=?");
            $stmt->execute([$refs[0]->id]);
        };
        $s->app->instance(AudienceGate::class, new AudienceGate($policy, $s->fake->surfaces));
        expect(FeedScenario::ids($f->page()))->toBe([$refs[1]->id, $refs[2]->id]);
    } finally {
        $s->cleanup();
    }
});

it('binds real cursors to full surface panel viewer generation installation and filters', function (string $case): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $f->tied(2);
        $a = $f->page(1);
        $handle = $s->handle;
        $filters = [];
        if ($case === 'filter') {
            $filters = ['author' => $handle->actor->actor?->toWire() ?? 'user:1'];
        }
        if ($case === 'panel') {
            $handle = $s->app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface('acme.b'));
        }
        if (in_array($case, ['viewer', 'generation', 'installation'], true)) {
            $old = $handle->actor;
            $actor = new ActorContext(actor: $case === 'viewer' ? new ActorRef('user', 'another') : $old->actor,
                kind: $old->kind, installation: $case === 'installation' ? new InstallationRef('other') : $old->installation,
                ageBand: $old->ageBand, provenance: $old->provenance, generation: $case === 'generation' ? 'changed' : $old->generation);
            $s->fake->actors->actingAs($actor);
            if ($case !== 'installation') {
                $handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
            }
            $s->fake->surfaces->allow($actor, $handle->surface);
        }
        expect(fn () => $s->posts->feed($handle, new FeedQuery(1, $a->nextCursor, $filters)))->toThrow($case === 'installation' ? SocKitException::class : InvalidFeedCursorException::class);
    } finally {
        $s->cleanup();
    }
})->with(['filter', 'panel', 'viewer', 'generation', 'installation']);

it('holds a feed shortlist across independent process commits without losing chronological position', function (string $action): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $refs = $f->tied(3);
        $worker = new FeedWorker($action, $refs[0]->id);
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $policy->afterBatch = fn () => $worker->commit();
        $s->app->instance(AudienceGate::class, new AudienceGate($policy, $s->fake->surfaces));
        $a = $f->page();
        $expected = $action === 'delete' ? [$refs[1]->id, $refs[2]->id] : [$refs[0]->id, $refs[1]->id];
        expect(FeedScenario::ids($a))->toBe($expected);
        if ($action === 'edit') {
            expect($a->items[0]->body)->toBe('concurrent edit');
        }
        if ($action === 'restore') {
            expect($a->items[0]->revision->value)->toBe(3);
        }
        if ($action !== 'delete') {
            expect(FeedScenario::ids($f->page(cursor: $a->nextCursor)))->toBe([$refs[2]->id]);
        }
        if ($action === 'insert') {
            expect($f->page(1)->items[0]->body)->toBe('concurrent above cursor');
        }
        $s->assertSentinels();
    } finally {
        $s->cleanup();
    }
})->with(['insert', 'edit', 'restore', 'delete']);
