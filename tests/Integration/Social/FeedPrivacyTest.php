<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Data\MediaReference;
use SocKit\Posts\Data\PostCommand;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\FeedPolicy;
use SocKit\Tests\Fixtures\Social\FeedScenario;
use SocKit\Tests\Fixtures\Social\MediaAdapter;
use SocKit\Tests\Fixtures\Social\PostLifecycle;

it('excludes every unavailable lifecycle even from an old Published shortlist', function (string $state): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $refs = $f->tied(2);
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $policy->afterBatch = function () use ($s, $refs, $state): void {
            $life = new PostLifecycle($s);
            if ($state === 'erased') {
                $life->erased($refs[0]);
            } elseif ($state === 'pending') {
                $s->connection()->table('panel_a.a_posts')->where('id', $refs[0]->id)->update(['publication_state' => 'pending']);
            } else {
                expect($life->execute($refs[0], $state, 1)->operation->isSuccess())->toBeTrue();
            }
        };
        $s->app->instance(AudienceGate::class, new AudienceGate($policy, $s->fake->surfaces));
        $page = $f->page();
        expect(FeedScenario::ids($page))->toBe([$refs[1]->id]);
        expect(json_encode($page))->not->toContain($refs[0]->id, 'totalCount', 'reaction', 'target_key');
        $s->assertSentinels();
    } finally {
        $s->cleanup();
    }
})->with(['hide', 'delete', 'hold', 'reject', 'pending', 'erased']);

it('does not emit pending posts or fabricate a publication position', function (): void {
    $f = new FeedScenario;
    try {
        $f->add('pending body', publish: false);
        expect($f->page()->items)->toBe([])->and($f->page()->nextCursor)->toBeNull();
    } finally {
        $f->s->cleanup();
    }
});

it('fails closed for missing batch keys provider errors and explicit batch deny', function (string $mode): void {
    $f = new FeedScenario(budget: 1);
    $s = $f->s;
    try {
        $refs = $f->tied(2);
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $policy->mode = $mode;
        $policy->denied = array_column($refs, 'id');
        $s->app->instance(AudienceGate::class, new AudienceGate($policy, $s->fake->surfaces));
        $a = $f->page();
        $b = $f->page(cursor: $a->nextCursor);
        expect($a->items)->toBe([])->and($b->items)->toBe([])->and($a->nextCursor)->not->toBe($b->nextCursor);
        expect($f->page(cursor: $b->nextCursor)->nextCursor)->toBeNull();
    } finally {
        $s->cleanup();
    }
})->with(['missing', 'error', 'normal']);

it('rechecks direct shrink same scope class replacement and membership revocation after batch admission', function (string $case): void {
    $f = new FeedScenario(contours: true);
    $s = $f->s;
    try {
        $actor = $s->handle->actor->actor ?? throw new RuntimeException('actor');
        $a = new HostEntityRef('class', '42');
        $b = new HostEntityRef('class', '43');
        foreach ([$a, $b] as $class) {
            $s->fake->contours->live(actor: $actor, level: 'class', contour: $class);
        }
        $spec = $case === 'direct' ? AudienceSpec::direct($actor, new ActorRef('user', 'second')) : AudienceSpec::contour('class', $a);
        $s->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $spec, decision: AuthorizationDecision::allow('fixture.set'));
        $ref = $f->add('private secret', $spec);
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $policy->afterBatch = function () use ($s, $ref, $case, $actor, $a, $b): void {
            if ($case === 'membership') {
                $s->fake->contours->revoke(actor: $actor, level: 'class', contour: $a);

                return;
            }
            $spec = $case === 'direct' ? AudienceSpec::direct(new ActorRef('user', 'second')) : AudienceSpec::contour('class', $b);
            $s->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $spec, decision: AuthorizationDecision::allow('fixture.set'));
            if ($case === 'class') {
                expect(new PostLifecycle($s)->execute($ref, 'audience', 1, $spec)->operation->isSuccess())->toBeTrue();
                $s->fake->contours->revoke(actor: $actor, level: 'class', contour: $b);
            } else {
                // Host source changed its current grant; a stale shortlist is deliberately retained.
                $s->connection()->table('panel_a.a_posts')->where('id', $ref->id)->update(['audience' => json_encode($spec->toWire()), 'privacy_revision' => 2]);
            }
        };
        $s->app->instance(AudienceGate::class, new AudienceGate($policy, $s->fake->surfaces));
        expect($f->page()->items)->toBe([]);
        expect(fn () => $s->posts->read($s->handle, $ref))->toThrow(SocKitException::class);
    } finally {
        $s->cleanup();
    }
})->with(['direct', 'class', 'membership']);

it('rechecks post and surface generations changed between scalar gate and body hydration', function (string $case): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $ref = $f->add('captured secret');
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $policy->afterScalar = function () use ($s, $ref, $case): void {
            if ($case === 'audience') {
                $s->connection()->table('panel_a.a_posts')->where('id', $ref->id)->update(['audience' => json_encode(AudienceSpec::direct(new ActorRef('user', 'other'))->toWire()), 'privacy_revision' => 2]);
            } elseif ($case === 'surface') {
                $s->connection()->table('panel_a.a_surface_instances')->increment('privacy_generation');
                $s->fake->surfaces->deny($s->handle->actor, $s->handle->surface);
            } elseif ($case === 'host') {
                $s->connection()->table('panel_a.envelope_targets')->update(['visible' => false, 'generation' => 'revoked']);
            } elseif ($case === 'edit') {
                $s->connection()->table('panel_a.a_posts')->where('id', $ref->id)->update(['body' => 'current body', 'revision' => 2]);
            } else {
                $s->connection()->table('panel_a.a_posts')->where('id', $ref->id)->update(['visibility' => 'hidden', 'safety_generation' => 2]);
            }
        };
        $s->app->instance(AudienceGate::class, new AudienceGate($policy, $s->fake->surfaces));
        $page = $f->page();
        if ($case === 'edit') {
            expect($page->items[0]->body)->toBe('current body')->and($page->items[0]->revision->value)->toBe(2);
        } else {
            expect($page->items)->toBe([]);
        }
        expect(json_encode($page))->not->toContain('captured secret');
    } finally {
        $s->cleanup();
    }
})->with(['audience', 'surface', 'host', 'edit', 'safety']);

it('fails closed on repeated owner instability during media resolution without remote calls in transactions', function (): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $s->app->instance(MediaResolver::class, new MediaAdapter($s->connection()));
        $post = new PostCommand(surface: $s->handle->surface, body: 'unstable secret', audience: $s->post()->audience, publish: true, media: [new MediaReference('host.media', 'private')]);
        $ref = $s->posts->create($s->handle, $post, $s->command($post))->operation->resource ?? throw new RuntimeException('Post');
        $resolver = new class($s->connection(), $ref->id) implements MediaResolver
        {
            public int $calls = 0;

            public function __construct(private readonly Connection $connection, private readonly string $id) {}

            public function associate(OperationHandle $handle, MediaReference $media): AuthorizationDecision
            {
                expect($this->connection->transactionLevel())->toBe(0);
                $this->connection->table('panel_a.a_posts')->where('id', $this->id)->increment('consent_generation');
                $this->calls++;

                return AuthorizationDecision::allow('fixture.media');
            }
        };
        $s->app->instance(MediaResolver::class, $resolver);
        expect($f->page()->items)->toBe([])->and($resolver->calls)->toBe(2);
    } finally {
        $s->cleanup();
    }
});

it('guards media currently and returns no counters or hidden total metadata', function (string $mode): void {
    $f = new FeedScenario(reactions: true);
    $s = $f->s;
    try {
        $media = new MediaAdapter($s->connection());
        $s->app->instance(MediaResolver::class, $media);
        $post = new PostCommand(surface: $s->handle->surface, body: 'protected body', audience: $s->post()->audience, publish: true, media: [new MediaReference('host.media', 'private-file')]);
        $ref = $s->posts->create($s->handle, $post, $s->command($post))->operation->resource ?? throw new RuntimeException('Post');
        $media->decision = $mode;
        $page = $f->page();
        if ($mode === 'allow') {
            expect(FeedScenario::ids($page))->toBe([$ref->id])->and($page->items[0]->media)->toEqual($post->media);
        } else {
            expect($page->items)->toBe([])->and(json_encode($page))->not->toContain('private-file', 'protected body', $ref->id);
        }
        expect(array_unique($media->levels))->toBe([0]);
        expect(array_keys(get_object_vars($page)))->toBe(['items', 'nextCursor']);
        expect(json_encode($page))->not->toContain('totalCount', 'reactionCount', 'ownKinds');
    } finally {
        $s->cleanup();
    }
})->with(['allow', 'deny', 'unknown', 'error']);

it('matches scalar admission for current author revoke archive and read only', function (string $case): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $ref = $f->add('body');
        if ($case === 'archive') {
            $s->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
        } elseif ($case === 'read-only') {
            $s->fake->surfaces->allowReadOnly($s->handle->actor, $s->handle->surface);
        } else {
            $s->fake->surfaces->deny($s->handle->actor, $s->handle->surface);
        }
        if ($case === 'revoke') {
            expect(fn () => $f->page())->toThrow(SocKitException::class);
            expect(fn () => $s->posts->read($s->handle, $ref))->toThrow(SocKitException::class);
        } else {
            expect($f->page()->items[0])->toEqual($s->posts->read($s->handle, $ref));
        }
    } finally {
        $s->cleanup();
    }
})->with(['archive', 'read-only', 'revoke']);

it('uses exact audience subjects and returns the same eligible set as scalar gates', function (): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $refs = $f->tied(3);
        $s->connection()->table('panel_a.a_posts')->where('id', $refs[1]->id)->update(['audience' => json_encode(AudienceSpec::direct(new ActorRef('user', 'other'))->toWire())]);
        $policy = new FeedPolicy($s->app->make(AudienceEvaluator::class));
        $gate = new AudienceGate($policy, $s->fake->surfaces);
        $s->app->instance(AudienceGate::class, $gate);
        $page = $f->page(10);
        expect(FeedScenario::ids($page))->toBe([$refs[0]->id, $refs[2]->id]);
        foreach ($policy->batches as $subjects) {
            $batch = $gate->readMany($s->handle->actor, $subjects);
            foreach ($subjects as $subject) {
                expect($subject)->toBeInstanceOf(AudienceSubject::class);
                expect($subject->installation)->toEqual($s->handle->actor->installation);
                expect($subject->surface)->toEqual($s->handle->surface);
                expect($batch[$subject->key])->toEqual($gate->read($s->handle->actor, $subject));
            }
        }
    } finally {
        $s->cleanup();
    }
});

it('allows current archived contour readers consistently with scalar reads', function (): void {
    $f = new FeedScenario(contours: true);
    $s = $f->s;
    try {
        $actor = $s->handle->actor->actor ?? throw new RuntimeException('Actor');
        $class = new HostEntityRef('class', '42');
        $spec = AudienceSpec::contour('class', $class);
        $s->fake->contours->live(actor: $actor, level: 'class', contour: $class);
        $s->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $spec, decision: AuthorizationDecision::allow('fixture.set'));
        $ref = $f->add('archive', $spec);
        $s->fake->contours->archived(actor: $actor, level: 'class', contour: $class);
        $s->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
        expect($f->page()->items[0])->toEqual($s->posts->read($s->handle, $ref));
    } finally {
        $s->cleanup();
    }
});

it('rechecks earlier buffered bodies after a later candidate changes their privacy and refills the hole', function (): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $refs = $f->tied(3);
        $queries = 0;
        $s->connection()->listen(function ($event) use (&$queries, $s, $refs): void {
            // The second candidate's body read is later than the first candidate's final scalar gate.
            if (str_starts_with($event->sql, 'select * from "panel_a"."driver_a_posts"') && in_array($refs[1]->id, $event->bindings, true)) {
                $queries++;
                if ($queries === 1) {
                    $s->connection()->table('panel_a.a_posts')->where('id', $refs[0]->id)->update(['visibility' => 'hidden', 'privacy_revision' => 2]);
                }
            }
        });
        expect(FeedScenario::ids($f->page()))->toBe([$refs[1]->id, $refs[2]->id]);
        expect($queries)->toBeGreaterThan(0);
    } finally {
        $s->cleanup();
    }
});

it('rejects ambient transactions and stale trusted handles before feed output', function (string $case): void {
    $f = new FeedScenario;
    $s = $f->s;
    try {
        $f->add('private');
        if ($case === 'transaction') {
            $s->connection()->beginTransaction();
        } else {
            $s->fake->actors->actingAs(RuntimeScenario::actor(installation: 'other'));
        }
        try {
            expect(fn () => $f->page())->toThrow(SocKitException::class);
        } finally {
            if ($case === 'transaction') {
                $s->connection()->rollBack();
            }
        }
        expect(array_unique($s->target->levels))->toBe([0]);
    } finally {
        $s->cleanup();
    }
})->with(['transaction', 'installation']);

it('checks the whole owner page after B final media hydration revokes earlier A on a valid continuation', function (string $change, int $budget): void {
    $f = new FeedScenario(budget: $budget);
    $s = $f->s;
    try {
        $refs = $f->tied(5);
        $first = $f->page(1);
        expect(FeedScenario::ids($first))->toBe([$refs[0]->id])->and($first->nextCursor)->not->toBeNull();
        [$a, $b] = [$refs[1], $refs[2]];
        $aBody = $s->posts->read($s->handle, $a)->body;
        $s->connection()->table('panel_a.a_posts')->where('id', $b->id)->update(['media_refs' => json_encode([['owner' => 'host.media', 'id' => 'b-private-media']])]);
        $resolver = new class($s->connection(), $a->id, $change) implements MediaResolver
        {
            public int $calls = 0;

            public ?int $revocationAt = null;

            public function __construct(private readonly Connection $connection, private readonly string $a, private readonly string $change) {}

            public function associate(OperationHandle $handle, MediaReference $media): AuthorizationDecision
            {
                expect($this->connection->transactionLevel())->toBe(0);
                expect($media->id)->toBe('b-private-media');
                $this->calls++;
                // B's first hydrate must finish normally. Only its final hydrate revokes already checked A.
                if ($this->calls === 2) {
                    $this->revocationAt = $this->calls;
                    if ($this->change === 'post') {
                        $this->connection->table('panel_a.a_posts')->where('id', $this->a)->update(['visibility' => 'hidden', 'privacy_revision' => 2]);
                    } else {
                        $this->connection->table('panel_a.a_surface_instances')->increment('privacy_generation');
                    }
                }

                return AuthorizationDecision::allow('fixture.current_media');
            }
        };
        $s->app->instance(MediaResolver::class, $resolver);
        $page = $f->page(2, $first->nextCursor);
        expect($resolver->revocationAt)->toBe(2);
        if ($change === 'post') {
            expect(fn () => $s->posts->read($s->handle, $a))->toThrow(SocKitException::class);
        }
        $expected = $budget === 2 ? [$b->id] : [$b->id, $refs[3]->id];
        expect(FeedScenario::ids($page))->toBe($expected);
        expect(json_encode($page))->not->toContain($a->id, $aBody);
        expect($page->nextCursor)->not->toBeNull();
        $next = $f->page(2, $page->nextCursor);
        expect(FeedScenario::ids($next))->toBe($budget === 2 ? [$refs[3]->id, $refs[4]->id] : [$refs[4]->id]);
        $s->assertSentinels();
    } finally {
        $s->cleanup();
    }
})->with(['post', 'surface'])->with([2, 3]);
