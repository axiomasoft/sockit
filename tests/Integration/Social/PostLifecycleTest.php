<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Data\PostTransition;
use SocKit\Posts\Enums\SafetyDecision;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\PostConsent;
use SocKit\Tests\Fixtures\Social\PostLifecycle;
use SocKit\Tests\Fixtures\Social\PostScenario;
use SocKit\Tests\Fixtures\Social\SafetyAdapter;

$matrix = [];
foreach (['pending', 'published', 'held', 'rejected'] as $state) {
    foreach (['visible', 'hidden'] as $visibility) {
        foreach (['publish', 'hold', 'reject', 'hide', 'restore', 'delete', 'audience'] as $action) {
            $matrix[$state.' '.$visibility.' -> '.$action] = [$state, $visibility, $action];
        }
    }
}

it('executes the explicit publication and visibility CAS matrix with immutable actor reason and privacy history', function (string $state, string $visibility, string $action): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $safety = new SafetyAdapter($s->connection(), match ($state) {
            'held' => SafetyDecision::Hold, 'rejected' => SafetyDecision::Reject, default => SafetyDecision::Allow,
        });
        $s->app->instance(ContentSafety::class, $safety);
        $post = $s->post(publish: $state !== 'pending');
        $created = $s->posts->create(handle: $s->handle, post: $post, command: $s->command($post));
        $ref = $created->operation->resource ?? throw new RuntimeException('Missing post');
        $published = $life->row($ref)['published_at'];
        expect($created->publication?->value)->toBe($state);
        $expected = 1;
        if ($visibility === 'hidden') {
            expect($life->execute($ref, 'hide', $expected++)->operation->isSuccess())->toBeTrue();
        }
        $safety->decision = SafetyDecision::Allow;
        $transition = $life->transition($ref, $action, $action === 'audience' ? $post->audience : null);
        $command = $life->command($transition, $expected);
        $allowed = match ($action) {
            'restore' => $visibility === 'hidden', 'delete' => true,
            'publish' => $visibility === 'visible' && $state !== 'published',
            'hold' => $visibility === 'visible' && $state !== 'held',
            'reject' => $visibility === 'visible' && $state !== 'rejected',
            default => $visibility === 'visible',
        };
        $before = $s->counts();
        $result = $s->posts->transition(handle: $s->handle, transition: $transition, command: $command);
        expect($result->operation->isSuccess())->toBe($allowed, json_encode($result, JSON_THROW_ON_ERROR));
        $row = $life->row($ref);
        if (! $allowed) {
            expect($row['revision'])->toBe($expected)->and($s->counts()['outbox'])->toBe($before['outbox']);

            return;
        }
        $newState = match ($action) {
            'publish' => 'published', 'hold' => 'held', 'reject' => 'rejected', default => $state,
        };
        $newVisibility = match ($action) {
            'hide' => 'hidden', 'restore' => 'visible', 'delete' => 'deleted', default => $visibility,
        };
        expect($row['revision'])->toBe($expected + 1)->and($row['privacy_revision'])->toBe($expected + 1)
            ->and($row['publication_state'])->toBe($newState)->and($row['visibility'])->toBe($newVisibility)
            ->and($result->isPublished())->toBe($newState === 'published' && $newVisibility === 'visible');
        if ($published !== null || $newState !== 'published') {
            expect($row['published_at'])->toBe($published);
        } else {
            expect($row['published_at'])->not->toBeNull();
        }
        $history = $s->connection()->table('panel_a.a_post_revisions')->where('revision', $expected + 1)->first();
        expect($history?->actor_ref)->toBe('user:7')->and($history?->reason)->toBe('host.reason.'.$action)
            ->and($history?->action)->toBe($action)->and($history?->publication_state)->toBe($newState)->and($history?->visibility)->toBe($newVisibility)
            ->and($history?->privacy_revision)->toBe($expected + 1);
        if ($action === 'delete') {
            expect($history?->body)->toBeNull()->and($history?->media_refs)->toBe('[]');
            expect($s->connection()->table('panel_a.a_post_revisions')->where('revision', 1)->value('body'))->toBe('First post');
        }
        $event = $life->event($expected + 1);
        expect($event['version'])->toBe('1.0')->and($event['privacy_revision'])->toBe($expected + 1)
            ->and($event['surface'])->toEqual($s->handle->surface->toWire())->and($event['payload'])->toEqual(['post' => $ref->toWire()]);
        if ($newVisibility !== 'visible' || $newState !== 'published') {
            expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
            PostLifecycle::denied($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key));
        }
        expect($s->counts()['revisions'])->toBe($before['revisions'] + 1)->and($s->counts()['outbox'])->toBe($before['outbox'] + 1);
    } finally {
        $s->cleanup();
    }
})->with($matrix);

it('requires fresh consent admission containment safety and action permission to restore a previously published post', function (string $case): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $ref = $s->posts->create(handle: $s->handle, post: $s->post(), command: $s->command())->operation->resource ?? throw new RuntimeException('Missing post');
        $firstPublished = $life->row($ref)['published_at'];
        expect($life->execute($ref, 'hide', 1)->operation->isSuccess())->toBeTrue();
        $restore = $life->transition($ref, 'restore');
        $command = $life->command($restore, 2);
        $consent = new PostConsent($s->connection());
        $s->app->instance(ActionAuthorizer::class, $consent);
        $s->app->instance(MutationAuthorityFence::class, $consent);
        match ($case) {
            'consent' => $s->connection()->table('panel_a.envelope_authority')->update(['allowed' => false, 'generation' => 2]),
            'permission' => $consent->denied = ['posts.restore'],
            'admission' => $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface),
            'containment' => $s->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $s->post()->audience ?? throw new RuntimeException('Audience'), decision: AuthorizationDecision::unknown('fixture.no_current_proof')),
            'safety' => $s->app->instance(ContentSafety::class, new SafetyAdapter($s->connection(), SafetyDecision::Hold)),
            'safety-error' => $s->app->instance(ContentSafety::class, new SafetyAdapter($s->connection(), fail: true)),
            default => throw new RuntimeException('Unknown fixture case'),
        };
        PostLifecycle::denied($s->posts->transition(handle: $s->handle, transition: $restore, command: $command)->operation);
        expect($life->row($ref)['visibility'])->toBe('hidden')->and($life->row($ref)['revision'])->toBe(2)->and($life->row($ref)['published_at'])->toBe($firstPublished);
        expect($s->counts()['outbox'])->toBe(2);
    } finally {
        $s->cleanup();
    }
})->with(['consent', 'permission', 'admission', 'containment', 'safety', 'safety-error']);

it('closes reads immediately after host revoke even when hide rolls back then retries the original key', function (): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $consent = new PostConsent($s->connection());
        $s->app->instance(ActionAuthorizer::class, $consent);
        $s->app->instance(MutationAuthorityFence::class, $consent);
        $create = $s->command();
        $ref = $s->posts->create(handle: $s->handle, post: $s->post(), command: $create)->operation->resource ?? throw new RuntimeException('Missing post');
        $hide = $life->transition($ref, 'hide');
        $command = $life->command($hide, 1);
        // Consent is host state, committed independently before any requested hide effect.
        $s->connection()->table('panel_a.envelope_authority')->update(['allowed' => false, 'generation' => 2]);
        $before = $s->counts();
        $failed = false;
        $s->connection()->listen(function (QueryExecuted $query) use (&$failed): void {
            $sql = strtolower($query->sql);
            if (! $failed && str_starts_with($sql, 'insert') && str_contains($sql, '"driver_a_outbox"')) {
                $failed = true;
                throw new RuntimeException('Hide persistence failed after consent commit');
            }
        });
        expect($s->posts->transition(handle: $s->handle, transition: $hide, command: $command)->operation->problem)->toBe(ProblemCode::ProviderUnavailable);
        expect($failed)->toBeTrue()->and($life->row($ref)['visibility'])->toBe('visible')->and($s->counts())->toBe($before);
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
        expect(fn () => $s->posts->revision(handle: $s->handle, resource: $ref, revision: new Revision(1)))->toThrow(SocKitException::class);
        PostLifecycle::denied($s->posts->lookup(handle: $s->handle, namespace: $create->namespace, key: $create->key)?->operation);
        PostLifecycle::denied($s->operations->lookup(handle: $s->handle, namespace: $create->namespace, key: $create->key));
        PostLifecycle::denied($s->posts->create(handle: $s->handle, post: $s->post(), command: $create)->operation);
        expect($s->posts->transition(handle: $s->handle, transition: $hide, command: $command)->operation->isSuccess())->toBeTrue();
        expect($life->row($ref)['visibility'])->toBe('hidden')->and($life->row($ref)['revision'])->toBe(2);
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 2, 'receipts' => 2, 'outbox' => 2]);
        expect($consent->levels)->each->toBe(0);
    } finally {
        $s->cleanup();
    }
});

it('promotes once using the original key and rejects a changed digest or stale revision', function (): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $draft = $s->post(publish: false);
        $ref = $s->posts->create(handle: $s->handle, post: $draft, command: $s->command($draft))->operation->resource ?? throw new RuntimeException('Missing post');
        $publish = $life->transition($ref, 'publish');
        $command = $life->command($publish, 1);
        $first = $s->posts->transition(handle: $s->handle, transition: $publish, command: $command);
        expect($first->isPublished())->toBeTrue();
        $before = $s->counts();
        expect($s->posts->transition(handle: $s->handle, transition: $publish, command: $command))->toEqual($first);
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->toWire())->toEqual($first->operation->toWire());
        $changed = new PostTransition(surface: $s->handle->surface, resource: $ref, action: 'publish', reason: 'different.reason');
        $conflict = $s->posts->transition(handle: $s->handle, transition: $changed, command: $life->command($changed, 1, $command->key->value));
        expect($conflict->operation->problem)->toBe(ProblemCode::IdempotencyConflict);
        expect($life->execute($ref, 'hide', 1)->operation->problem)->toBe(ProblemCode::RevisionConflict);
        expect($s->counts()['revisions'])->toBe($before['revisions'])->and($s->counts()['outbox'])->toBe($before['outbox']);
    } finally {
        $s->cleanup();
    }
});

it('never restores or promotes a deleted or erased owner record including a delayed precomputed command', function (string $terminal): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $draft = $s->post(publish: false);
        $create = $s->command($draft);
        $ref = $s->posts->create(handle: $s->handle, post: $draft, command: $create)->operation->resource ?? throw new RuntimeException('Missing post');
        $commands = [];
        foreach (['publish', 'hold', 'reject', 'hide', 'restore', 'delete', 'audience'] as $action) {
            $transition = $life->transition($ref, $action, $action === 'audience' ? $draft->audience : null);
            $commands[] = [$transition, $life->command($transition, 1)];
        }
        $edit = $s->post(body: 'Delayed publish', resource: $ref);
        $editCommand = $s->command(post: $edit, expected: new Revision(1), key: 'post.delayed.edit.0001');
        if ($terminal === 'deleted') {
            expect($life->execute($ref, 'delete', 1)->operation->isSuccess())->toBeTrue();
        } else {
            $life->erased($ref);
        }
        $before = $s->counts();
        foreach ($commands as [$transition, $command]) {
            expect($s->posts->transition(handle: $s->handle, transition: $transition, command: $command)->operation->problem)
                ->toBe($terminal === 'deleted' ? ProblemCode::ContentDeleted : ProblemCode::ContentErased);
        }
        PostLifecycle::denied($s->posts->edit(handle: $s->handle, post: $edit, command: $editCommand)->operation);
        PostLifecycle::denied($s->operations->lookup(handle: $s->handle, namespace: $create->namespace, key: $create->key));
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
        expect($s->counts())->toBe($before);
    } finally {
        $s->cleanup();
    }
})->with(['deleted', 'erased']);

it('keeps moderator hide distinct from author delete and records the trusted moderator', function (): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $author = $s->handle->actor;
        $moderator = RuntimeScenario::actor('8');
        $audience = AudienceSpec::direct($author->actor ?? throw new RuntimeException('Author'), $moderator->actor ?? throw new RuntimeException('Moderator'));
        $s->fake->surfaces->containment(actor: $author, surface: $s->handle->surface, proposed: $audience, decision: AuthorizationDecision::allow('fixture.exact_set'));
        $post = $s->post(audience: $audience);
        $ref = $s->posts->create(handle: $s->handle, post: $post, command: $s->command($post))->operation->resource ?? throw new RuntimeException('Missing post');
        $delete = $life->transition($ref, 'delete');
        $deleteCommand = $life->command($delete, 1);
        $s->fake->actors->actingAs($moderator);
        $s->fake->surfaces->allow(viewer: $moderator, surface: $s->handle->surface);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        expect($s->posts->transition(handle: $s->handle, transition: $delete, command: $deleteCommand)->operation->problem)->toBe(ProblemCode::AuthorizationDenied);
        expect($life->execute($ref, 'hide', 1)->operation->isSuccess())->toBeTrue();
        $history = $s->connection()->table('panel_a.a_post_revisions')->where('revision', 2)->first();
        expect($history?->actor_ref)->toBe('user:8')->and($history?->action)->toBe('hide')->and($history?->reason)->toBe('host.reason.hide');
        expect($life->row($ref)['author_ref'])->toBe('user:7')->and($life->row($ref)['publication_state'])->toBe('published');
        $s->counts();
    } finally {
        $s->cleanup();
    }
});

it('fences a changed host consent generation after the owner lock and retries the original intent only after rollback', function (): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $draft = $s->post(publish: false);
        $ref = $s->posts->create(handle: $s->handle, post: $draft, command: $s->command($draft))->operation->resource ?? throw new RuntimeException('Missing post');
        $publish = $life->transition($ref, 'publish');
        $command = $life->command($publish, 1);
        $changed = false;
        $s->connection()->listen(function (QueryExecuted $query) use ($s, &$changed): void {
            if (! $changed && str_contains($query->sql, '"driver_a_posts"') && str_contains($query->sql, 'for update')) {
                $changed = true;
                // Independent PDO, committed host generation change before the authority row is locked.
                $s->db->owner->exec('UPDATE panel_a.driver_envelope_authority SET generation=2');
            }
        });
        expect($s->posts->transition(handle: $s->handle, transition: $publish, command: $command)->operation->problem)->toBe(ProblemCode::AuthorizationUnknown);
        expect($changed)->toBeTrue()->and($life->row($ref)['revision'])->toBe(1)->and($life->row($ref)['publication_state'])->toBe('pending');
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 1, 'receipts' => 1, 'outbox' => 1]);
        expect($s->posts->transition(handle: $s->handle, transition: $publish, command: $command)->isPublished())->toBeTrue();
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 2, 'receipts' => 2, 'outbox' => 2]);
    } finally {
        $s->cleanup();
    }
});
