<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Enums\SafetyDecision;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\PostLifecycle;
use SocKit\Tests\Fixtures\Social\PostReactionScenario;
use SocKit\Tests\Fixtures\Social\ReactionScenario;
use SocKit\Tests\Fixtures\Social\SafetyAdapter;

it('keeps the reactions only host path independent of any offered posts capability', function (): void {
    $s = new ReactionScenario;
    try {
        expect($s->execute()->isSuccess())->toBeTrue();
        $before = count($s->target->levels);
        $target = new TargetRef(host: new HostEntityRef('sockit.post', '42'), incarnation: new Incarnation(1));
        expect(fn () => $s->app->make(OperationHandles::class)->resolveTarget(handle: $s->handle, target: $target))->toThrow(SocKitException::class);
        expect(count($s->target->levels))->toBe($before);
        expect($s->connection()->scalar("SELECT to_regclass('panel_a.driver_a_posts')"))->toBeNull();
        expect($s->counts()['count'])->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('selects the posts registry only on its enabled panel and never falls back to a permissive host', function (): void {
    $f = new PostReactionScenario(posts: false);
    $s = $f->s;
    try {
        $missing = new ResourceRef(panel: $s->handle->surface->panel, kind: ResourceKind::Post, id: '42');
        foreach (['post', 'sockit.post'] as $alias) {
            $before = count($s->target->levels);
            PostLifecycle::denied($f->add($f->target($missing, alias: $alias)));
            expect(count($s->target->levels))->toBe($before);
        }
        $host = new TargetRef(host: $s->handle->surface->host, incarnation: $s->handle->surface->incarnation);
        expect($f->add($host)->isSuccess())->toBeTrue();
        expect($s->connection()->scalar("SELECT to_regclass('panel_a.driver_a_posts')"))->toBeNull();
        expect($f->counts())->toBe(['members' => 1, 'active' => 1, 'count' => 1, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});

it('resolves exact current metadata and fences canonical post reactions through public entry and lookup', function (): void {
    $f = new PostReactionScenario;
    $s = $f->s;
    try {
        $ref = $f->create();
        $target = $f->target($ref);
        $resolved = $s->app->make(OperationHandles::class)->resolveTarget(handle: $s->handle, target: $target);
        expect($resolved->surface)->toEqual($s->handle->surface)->and($resolved->target)->toEqual($target)
            ->and($resolved->installation)->toEqual($s->handle->actor->installation)->and($resolved->read->canRead())->toBeTrue()
            ->and($resolved->write->canWrite())->toBeTrue()->and($resolved->privacyRevision->value)->toBe(1)->and($resolved->generation)->not->toBe('');
        // The resource-kind alias stays read-only so it cannot create a second membership identity.
        $legacy = $s->app->make(OperationHandles::class)->resolveTarget(handle: $s->handle, target: $f->target($ref, alias: 'post'));
        expect($legacy->read->canRead())->toBeTrue()->and($legacy->write->canWrite())->toBeFalse();
        $command = $f->command($target);
        $first = $f->add($target, $command);
        expect($first->isSuccess())->toBeTrue()->and($first->revision?->value)->toBe(1);
        expect($f->add($target, $command)->resource)->toEqual($first->resource);
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->resource)->toEqual($first->resource);
        expect($f->reactions->summary(handle: $s->handle, target: $target)->counts)->toBe(['like' => 1]);
        $f->reactions->rebuild(handle: $s->handle, target: $target);
        expect($f->counts())->toBe(['members' => 1, 'active' => 1, 'count' => 1, 'receipts' => 1, 'outbox' => 1]);
        expect(array_unique($s->target->levels))->toBe([0])->and($s->target->fences)->toBeGreaterThan(1);
    } finally {
        $s->cleanup();
    }
});

it('denies non public post states without a membership counter or event', function (string $state): void {
    $f = new PostReactionScenario;
    $s = $f->s;
    try {
        if (in_array($state, ['held', 'rejected'], true)) {
            $s->app->instance(ContentSafety::class, new SafetyAdapter($s->connection(), $state === 'held' ? SafetyDecision::Hold : SafetyDecision::Reject));
        }
        $ref = $f->create(publish: $state !== 'pending');
        if (in_array($state, ['hidden', 'deleted'], true)) {
            expect($f->life->execute($ref, $state === 'hidden' ? 'hide' : 'delete', 1)->operation->isSuccess())->toBeTrue();
        } elseif ($state === 'erased') {
            $f->life->erased($ref);
        }
        $target = $f->target($ref);
        PostLifecycle::denied($f->add($target));
        expect($f->reactions->summary(handle: $s->handle, target: $target)->counts)->toBeNull();
        expect(fn () => $f->reactions->rebuild(handle: $s->handle, target: $target))->toThrow(SocKitException::class);
        expect($f->counts())->toBe(['members' => 0, 'active' => 0, 'count' => 0, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['pending', 'held', 'rejected', 'hidden', 'deleted', 'erased']);

it('rejects wrong incarnation panel surface and installation even when numeric post IDs match', function (string $case): void {
    $f = new PostReactionScenario;
    $s = $f->s;
    try {
        $ref = $f->create();
        $target = $f->target($ref, incarnation: $case === 'incarnation' ? 2 : 1);
        $command = $f->command($target);
        if ($case === 'panel') {
            // Fixture-only same ID in B cannot grant A anything about that other panel's row.
            $row = $f->life->row($ref);
            $row['panel'] = 'acme.b';
            $row['body'] = 'B sentinel';
            $s->connection('b')->table('panel_b.b_posts')->insert($row);
            $s->connection()->table('panel_a.a_posts')->where('id', $ref->id)->update(['visibility' => 'hidden']);
        } elseif ($case === 'surface') {
            $old = $s->handle->surface;
            $surface = new SurfaceRef(panel: $old->panel, kind: $old->kind, host: new HostEntityRef('article', '43'), incarnation: $old->incarnation);
            $s->fake->surfaces->allow(viewer: $s->handle->actor, surface: $surface);
            $s->handle = $s->app->make(OperationHandles::class)->forSurface($surface);
        } elseif ($case === 'installation') {
            $s->fake->actors->actingAs(RuntimeScenario::actor(installation: 'foreign'));
        }
        PostLifecycle::denied($f->add($target, $command));
        expect($f->reactions->summary(handle: $s->handle, target: $target)->counts)->toBeNull();
        // B sentinel was deliberately inserted here; the normal B sentinel assertion is for untouched fixtures.
        if ($case !== 'panel') {
            expect($f->counts()['members'])->toBe(0);
        } else {
            expect($s->connection('b')->table('panel_b.b_posts')->value('body'))->toBe('B sentinel');
            expect($s->connection()->table('panel_a.a_reactions')->count())->toBe(0);
        }
    } finally {
        $s->cleanup();
    }
})->with(['incarnation', 'panel', 'surface', 'installation']);

it('closes retained counts batch reads direct and generic receipt replay under current revoke', function (string $case): void {
    $f = new PostReactionScenario;
    $s = $f->s;
    try {
        $ref = $f->create();
        $f->actor(RuntimeScenario::actor('8'));
        $target = $f->target($ref);
        $command = $f->command($target);
        expect($f->add($target, $command)->isSuccess())->toBeTrue();
        $before = $f->counts();
        if ($case === 'shrink') {
            $f->actor(RuntimeScenario::actor());
            expect($f->life->execute($ref, 'audience', 1, AudienceSpec::direct(RuntimeScenario::actor()->actor ?? throw new RuntimeException('Owner')))->operation->isSuccess())->toBeTrue();
            $f->actor(RuntimeScenario::actor('8'));
        } elseif ($case === 'host') {
            $s->connection()->table('panel_a.envelope_targets')->update(['visible' => false]);
        } elseif ($case === 'authority') {
            $s->connection()->table('panel_a.envelope_authority')->update(['allowed' => false, 'generation' => 2]);
        } else {
            $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface);
        }
        PostLifecycle::denied($f->add($target, $command));
        PostLifecycle::denied($f->reactions->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key));
        PostLifecycle::denied($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key));
        expect($f->reactions->summary(handle: $s->handle, target: $target)->counts)->toBeNull();
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
        expect($f->counts())->toBe($before);
    } finally {
        $s->cleanup();
    }
})->with(['shrink', 'host', 'authority', 'admission']);

it('requires a provable host fence and keeps read only targets readable without granting new writes', function (string $case): void {
    $f = new PostReactionScenario;
    $s = $f->s;
    try {
        $target = $f->target($f->create());
        if ($case === 'unproven') {
            $s->target->unproven = true;
        } elseif ($case === 'host-change') {
            $s->target->changeAfterResolve = true;
        } elseif ($case === 'archive') {
            $s->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
        } else {
            $s->fake->surfaces->allowReadOnly(viewer: $s->handle->actor, surface: $s->handle->surface);
        }
        PostLifecycle::denied($f->add($target));
        expect($f->counts()['members'])->toBe(0);
        if (in_array($case, ['archive', 'read-only'], true)) {
            expect($f->reactions->summary(handle: $s->handle, target: $target)->counts)->toBe(['like' => 0]);
        }
    } finally {
        $s->cleanup();
    }
})->with(['unproven', 'host-change', 'archive', 'read-only']);

it('creates a distinct replacement post without letting an old incarnation attach to it', function (): void {
    $f = new PostReactionScenario;
    try {
        $old = $f->create();
        expect($f->add($f->target($old))->isSuccess())->toBeTrue();
        expect($f->life->execute($old, 'delete', 1)->operation->isSuccess())->toBeTrue();
        $new = $f->create(key: 'post.replacement.0001');
        expect($new->id)->not->toBe($old->id);
        foreach ([$f->target($old), $f->target($old, incarnation: 2), $f->target($new, incarnation: 2)] as $stale) {
            PostLifecycle::denied($f->add($stale));
        }
        $target = $f->target($new);
        expect($f->reactions->summary(handle: $f->s->handle, target: $target)->counts)->toBe(['like' => 0]);
        expect($f->add($target, $f->command($target, 'reaction.replacement.0001'))->isSuccess())->toBeTrue();
        expect($f->counts())->toBe(['members' => 2, 'active' => 2, 'count' => 2, 'receipts' => 2, 'outbox' => 2]);
        expect($f->reactions->summary(handle: $f->s->handle, target: $f->target($old))->counts)->toBeNull();
        expect($f->reactions->summary(handle: $f->s->handle, target: $target)->counts)->toBe(['like' => 1]);
    } finally {
        $f->s->cleanup();
    }
});
