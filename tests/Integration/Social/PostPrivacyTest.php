<?php

declare(strict_types=1);

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\PostLifecycle;
use SocKit\Tests\Fixtures\Social\PostScenario;

it('versions post events with the owning privacy revision instead of a host snapshot', function (): void {
    $s = new PostScenario;
    try {
        $created = $s->posts->create(handle: $s->handle, post: $s->post(), command: $s->command());
        $ref = $created->operation->resource ?? throw new RuntimeException('Missing post');
        $edit = $s->post(body: 'Second version', resource: $ref);
        expect($s->posts->edit(handle: $s->handle, post: $edit, command: $s->command(post: $edit, expected: new Revision(1), key: 'post.privacy.edit.0001'))->isPublished())->toBeTrue();
        $wire = new PostLifecycle($s)->event(2);
        expect($wire['privacy_revision'])->toBe(2);
        expect($s->connection()->table('panel_a.a_posts')->value('privacy_revision'))->toBe(2);
        $s->counts();
    } finally {
        $s->cleanup();
    }
});

it('removes a direct recipient at the same scope and gates every historical body by the current set', function (): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $owner = $s->handle->actor;
        $viewer = RuntimeScenario::actor('8');
        $audience = AudienceSpec::direct($owner->actor ?? throw new RuntimeException('Owner'), $viewer->actor ?? throw new RuntimeException('Viewer'));
        $s->fake->surfaces->containment(actor: $owner, surface: $s->handle->surface, proposed: $audience, decision: AuthorizationDecision::allow('fixture.exact_set'));
        $post = $s->post(audience: $audience);
        $ref = $s->posts->create(handle: $s->handle, post: $post, command: $s->command($post))->operation->resource ?? throw new RuntimeException('Missing post');
        $s->fake->surfaces->allow(viewer: $viewer, surface: $s->handle->surface);
        $s->fake->actors->actingAs($viewer);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        expect($s->posts->read(handle: $s->handle, resource: $ref)->body)->toBe('First post');
        $s->fake->actors->actingAs($owner);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        expect($life->execute($ref, 'audience', 1, AudienceSpec::direct($owner->actor ?? throw new RuntimeException('Owner')))->isPublished())->toBeTrue();
        expect($life->row($ref)['privacy_revision'])->toBe(2)->and($life->event(2)['event_type'])->toBe('posts.audience_changed');
        $s->fake->actors->actingAs($viewer);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
        expect(fn () => $s->posts->revision(handle: $s->handle, resource: $ref, revision: new Revision(1)))->toThrow(SocKitException::class);
        $s->counts();
    } finally {
        $s->cleanup();
    }
});

it('replaces class A by class B at the same scope and denies old receipt reads after current author removal', function (): void {
    $s = new PostScenario(privacy: ['floor' => ['allowed_scopes' => ['direct', 'contour'], 'default_scope' => 'direct', 'max_scope' => 'contour', 'minor_default_scope' => 'direct', 'minor_max_scope' => 'direct', 'contour_levels' => ['class']]]);
    try {
        $life = new PostLifecycle($s);
        $actor = $s->handle->actor->actor ?? throw new RuntimeException('Actor');
        $classA = new HostEntityRef('class', '42');
        $classB = new HostEntityRef('class', '43');
        $a = AudienceSpec::contour('class', $classA);
        $b = AudienceSpec::contour('class', $classB);
        foreach ([$classA, $classB] as $class) {
            $s->fake->contours->live(actor: $actor, level: 'class', contour: $class);
        }
        foreach ([$a, $b] as $audience) {
            $s->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $audience, decision: AuthorizationDecision::allow('host.current_class_set'));
        }
        $post = $s->post(audience: $a);
        $create = $s->command($post);
        $ref = $s->posts->create(handle: $s->handle, post: $post, command: $create)->operation->resource ?? throw new RuntimeException('Missing post');
        expect($life->execute($ref, 'audience', 1, $b)->isPublished())->toBeTrue();
        expect($life->row($ref)['privacy_revision'])->toBe(2);
        expect($s->posts->read(handle: $s->handle, resource: $ref)->audience)->toEqual($b);
        $s->fake->contours->revoke(actor: $actor, level: 'class', contour: $classB);
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
        expect(fn () => $s->posts->revision(handle: $s->handle, resource: $ref, revision: new Revision(1)))->toThrow(SocKitException::class);
        PostLifecycle::denied($s->operations->lookup(handle: $s->handle, namespace: $create->namespace, key: $create->key));
        PostLifecycle::denied($s->posts->lookup(handle: $s->handle, namespace: $create->namespace, key: $create->key)?->operation);
        PostLifecycle::denied($s->posts->create(handle: $s->handle, post: $post, command: $create)->operation);
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 2, 'receipts' => 2, 'outbox' => 2]);
    } finally {
        $s->cleanup();
    }
});

it('rejects audience changes without current set containment even for a narrower rank or another class', function (string $case): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $ref = $s->posts->create(handle: $s->handle, post: $s->post(), command: $s->command())->operation->resource ?? throw new RuntimeException('Missing post');
        $proposed = match ($case) {
            'class' => AudienceSpec::contour('class', new HostEntityRef('class', '43')),
            'group' => AudienceSpec::group(new GroupRef('unrelated')),
            default => $s->post()->audience ?? throw new RuntimeException('Audience'),
        };
        $s->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $proposed, decision: AuthorizationDecision::unknown('fixture.set_inclusion_unproven'));
        PostLifecycle::denied($life->execute($ref, 'audience', 1, $proposed)->operation);
        expect($life->row($ref)['revision'])->toBe(1)->and($s->counts()['outbox'])->toBe(1);
    } finally {
        $s->cleanup();
    }
})->with(['class', 'group', 'unknown']);

it('keeps eligible archived and read only reads while denying every new lifecycle write', function (string $case): void {
    $s = new PostScenario;
    try {
        $life = new PostLifecycle($s);
        $create = $s->command();
        $ref = $s->posts->create(handle: $s->handle, post: $s->post(), command: $create)->operation->resource ?? throw new RuntimeException('Missing post');
        $commands = [];
        foreach (['hide', 'hold', 'reject', 'delete', 'audience'] as $action) {
            $transition = $life->transition($ref, $action, $action === 'audience' ? $s->post()->audience : null);
            $commands[] = [$transition, $life->command($transition, 1)];
        }
        if ($case === 'archive') {
            $s->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']);
        } else {
            $s->fake->surfaces->allowReadOnly(viewer: $s->handle->actor, surface: $s->handle->surface);
        }
        expect($s->posts->read(handle: $s->handle, resource: $ref)->body)->toBe('First post');
        expect($s->posts->revision(handle: $s->handle, resource: $ref, revision: new Revision(1))->body)->toBe('First post');
        expect($s->operations->lookup(handle: $s->handle, namespace: $create->namespace, key: $create->key)?->isSuccess())->toBeTrue();
        foreach ($commands as [$transition, $command]) {
            PostLifecycle::denied($s->posts->transition(handle: $s->handle, transition: $transition, command: $command)->operation);
        }
        expect($life->row($ref)['revision'])->toBe(1)->and($s->counts()['outbox'])->toBe(1);
    } finally {
        $s->cleanup();
    }
})->with(['archive', 'read-only']);

it('denies bodies historical revisions generic lookup and replay after admission or isolation revocation', function (string $case): void {
    $s = new PostScenario;
    try {
        $create = $s->command();
        $ref = $s->posts->create(handle: $s->handle, post: $s->post(), command: $create)->operation->resource ?? throw new RuntimeException('Missing post');
        if ($case === 'installation') {
            $s->fake->actors->actingAs(RuntimeScenario::actor(installation: 'another'));
        } else {
            $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface);
        }
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
        expect(fn () => $s->posts->revision(handle: $s->handle, resource: $ref, revision: new Revision(1)))->toThrow(SocKitException::class);
        PostLifecycle::denied($s->operations->lookup(handle: $s->handle, namespace: $create->namespace, key: $create->key));
        PostLifecycle::denied($s->posts->lookup(handle: $s->handle, namespace: $create->namespace, key: $create->key)?->operation);
        PostLifecycle::denied($s->posts->create(handle: $s->handle, post: $s->post(), command: $create)->operation);
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 1, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
})->with(['installation', 'revoked-author']);
