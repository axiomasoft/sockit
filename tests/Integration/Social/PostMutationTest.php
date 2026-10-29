<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Data\MediaReference;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Enums\PublicationState;
use SocKit\Posts\Enums\SafetyDecision;
use SocKit\Posts\Internal\PostException;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\MediaAdapter;
use SocKit\Tests\Fixtures\Social\PostScenario;
use SocKit\Tests\Fixtures\Social\SafetyAdapter;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('creates and edits immutable published revisions through the real owner envelope', function (): void {
    $s = new PostScenario;
    try {
        $post = $s->post();
        $command = $s->command($post);
        $created = $s->posts->create(handle: $s->handle, post: $post, command: $command);
        expect($created->operation->isSuccess())->toBeTrue(json_encode($created, JSON_THROW_ON_ERROR))->and($created->publication)->toBe(PublicationState::Published)->and($created->isPublished())->toBeTrue();
        $ref = $created->operation->resource ?? throw new RuntimeException('Missing stored post');
        $first = $s->posts->read(handle: $s->handle, resource: $ref);
        expect($first->body)->toBe('First post')->and($first->revision->value)->toBe(1);
        $edit = $s->post(body: 'Edited post', resource: $ref);
        $updated = $s->posts->edit(handle: $s->handle, post: $edit, command: $s->command(post: $edit, expected: new Revision(1), key: 'post.edit.request.0001'));
        expect($updated->isPublished())->toBeTrue();
        $current = $s->posts->read(handle: $s->handle, resource: $ref);
        expect($current->body)->toBe('Edited post')->and($current->revision->value)->toBe(2)->and($current->publishedAt)->toEqual($first->publishedAt);
        expect($s->posts->revision(handle: $s->handle, resource: $ref, revision: new Revision(1))->body)->toBe('First post');
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 2, 'receipts' => 2, 'outbox' => 2]);
        $retried = $s->posts->create(handle: $s->handle, post: $post, command: $command);
        expect($retried->operation->isSuccess())->toBeTrue(json_encode($retried, JSON_THROW_ON_ERROR));
        expect($retried->operation->resource)->toEqual($ref);
        expect($s->counts()['revisions'])->toBe(2);
    } finally {
        $s->cleanup();
    }
});

it('records pending held and rejected states atomically without claiming a publication', function (string $state): void {
    $s = new PostScenario;
    try {
        if ($state !== 'pending') {
            $s->app->instance(ContentSafety::class, new SafetyAdapter($s->connection(), SafetyDecision::from($state === 'held' ? 'hold' : 'reject')));
        }
        $post = $s->post(publish: $state !== 'pending');
        $command = $s->command($post);
        $result = $s->posts->create(handle: $s->handle, post: $post, command: $command);
        expect($result->operation->isSuccess())->toBeTrue(json_encode($result, JSON_THROW_ON_ERROR))->and($result->isPublished())->toBeFalse()->and($result->publication?->value)->toBe($state);
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 1, 'receipts' => 1, 'outbox' => 1]);
        $row = $s->connection()->table('panel_a.a_posts')->first();
        expect($row?->published_at)->toBeNull()->and($row?->publication_state)->toBe($state);
        $ref = $result->operation->resource ?? throw new RuntimeException('Stored ref missing');
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(PostException::class);
    } finally {
        $s->cleanup();
    }
})->with(['pending', 'held', 'rejected']);

it('rolls back post immutable revision receipt and outbox after each real SQL stage', function (string $stage): void {
    $s = new PostScenario;
    try {
        $command = $s->command();
        $before = $s->counts();
        $fired = false;
        $s->connection()->listen(function (QueryExecuted $query) use ($stage, &$fired): void {
            $sql = strtolower($query->sql);
            $table = match ($stage) {
                'post' => '"driver_a_posts"', 'revision' => '"driver_a_post_revisions"', 'receipt' => '"driver_a_receipts"', default => '"driver_a_outbox"',
            };
            if (! $fired && str_contains($sql, $table) && str_starts_with($sql, $stage === 'receipt' ? 'update' : 'insert')) {
                $fired = true;
                throw new RuntimeException('Injected rollback after '.$stage);
            }
        });
        $result = $s->posts->create(handle: $s->handle, post: $s->post(), command: $command);
        expect($fired)->toBeTrue()->and($result->operation->problem)->toBe(ProblemCode::ProviderUnavailable);
        expect($s->counts())->toBe($before)->and($s->connection()->transactionLevel())->toBe(0);
        expect($s->posts->create(handle: $s->handle, post: $s->post(), command: $command)->isPublished())->toBeTrue();
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 1, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
})->with(['post', 'revision', 'receipt', 'outbox']);

it('enforces current host authority admission target incarnation and readonly boundaries before writing', function (string $mode): void {
    $s = new PostScenario(feature: $mode !== 'feature', module: $mode !== 'module');
    try {
        if (in_array($mode, ['feature', 'module'], true)) {
            $command = new CommandEnvelope(key: new CommandKey('post.request.invalid.0001'), namespace: new CommandNamespace(installation: $s->handle->actor->installation, panel: $s->handle->surface->panel, integration: 'host.client', commandType: 'posts.create'), digest: PayloadDigest::of(commandType: 'posts.create', payload: []));
        } else {
            $command = $s->command();
        }
        match ($mode) {
            'read-only' => $s->fake->surfaces->allowReadOnly(viewer: $s->handle->actor, surface: $s->handle->surface),
            'admission' => $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface),
            'archive' => $s->connection()->table('panel_a.a_surface_instances')->update(['lifecycle' => 'archived']),
            'consent' => $s->connection()->table('panel_a.envelope_authority')->update(['allowed' => false, 'generation' => 2]),
            'stale-target' => $s->target->changeAfterResolve = true,
            'unproven-target' => $s->target->unproven = true,
            'unproven-authority' => $s->fence->unproven = true,
            'hidden-host' => $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['visible' => false]),
            'host-held' => $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['held' => true]),
            'recycled-host' => $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['incarnation' => 2]),
            'installation' => $s->fake->actors->actingAs(RuntimeScenario::actor(installation: 'another')),
            default => null,
        };
        $result = $s->posts->create(handle: $s->handle, post: $s->post(), command: $command);
        expect($result->operation->isSuccess())->toBeFalse()->and($result->operation->resource)->toBeNull()->and($result->operation->problem)->not->toBeNull();
        expect($s->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'posts.%'")->count())->toBe(0);
        if ($mode !== 'module') {
            expect($s->counts()['posts'])->toBe(0)->and($s->counts()['revisions'])->toBe(0);
        }
        expect($s->connection()->transactionLevel())->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['read-only', 'admission', 'archive', 'consent', 'stale-target', 'unproven-target', 'unproven-authority', 'hidden-host', 'host-held', 'recycled-host', 'installation', 'feature', 'module']);

it('denies cross class unrelated group unproven containment and a scope outside the effective floor', function (string $mode): void {
    $s = new PostScenario;
    try {
        $audience = match ($mode) {
            'class' => AudienceSpec::contour('class', new HostEntityRef('class', '43')),
            'group' => AudienceSpec::group(new GroupRef('unrelated-group')),
            'floor' => AudienceSpec::public(),
            default => AudienceSpec::direct($s->handle->actor->actor ?? throw new RuntimeException('actor')),
        };
        $s->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $audience, decision: $mode === 'floor' ? AuthorizationDecision::allow('fixture.contained_but_floor_forbidden') : AuthorizationDecision::unknown('fixture.set_not_proven'));
        $post = $s->post(audience: $audience);
        $result = $s->posts->create(handle: $s->handle, post: $post, command: $s->command($post));
        expect($result->isPublished())->toBeFalse()->and($result->operation->resource)->toBeNull()->and($s->counts()['posts'])->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['class', 'group', 'unknown', 'floor']);

it('rechecks current post state for every body historical revision replay and generic resource lookup', function (string $mode): void {
    $s = new PostScenario;
    try {
        $command = $s->command();
        $created = $s->posts->create(handle: $s->handle, post: $s->post(), command: $command);
        $ref = $created->operation->resource ?? throw new RuntimeException('Post missing');
        $changes = match ($mode) {
            'held' => ['publication_state' => 'held'], 'hidden' => ['visibility' => 'hidden'], 'deleted' => ['visibility' => 'deleted'],
            'shrink' => ['audience' => CanonicalJson::encode(AudienceSpec::direct(new ActorRef('user', '8'))->toWire()), 'privacy_revision' => 2],
            default => [],
        };
        if ($changes !== []) {
            $s->connection()->table('panel_a.a_posts')->where('id', $ref->id)->update($changes);
        }
        if ($mode === 'revoke') {
            $s->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface);
        }
        expect(fn () => $s->posts->read(handle: $s->handle, resource: $ref))->toThrow(SocKitException::class);
        expect(fn () => $s->posts->revision(handle: $s->handle, resource: $ref, revision: new Revision(1)))->toThrow(SocKitException::class);
        $lookup = $s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
        expect($lookup?->isSuccess())->toBeFalse()->and($lookup?->resource)->toBeNull()->and($lookup?->revision)->toBeNull();
        $replay = $s->posts->create(handle: $s->handle, post: $s->post(), command: $command);
        expect($replay->operation->resource)->toBeNull()->and($replay->operation->revision)->toBeNull();
        expect($s->counts()['posts'])->toBe(1)->and($s->counts()['revisions'])->toBe(1)->and($s->counts()['outbox'])->toBe(1);
    } finally {
        $s->cleanup();
    }
})->with(['held', 'hidden', 'deleted', 'shrink', 'revoke']);

it('persists only approved media associations and fails unknown safety or media before effects', function (string $mode): void {
    $s = new PostScenario;
    try {
        $ref = new MediaReference('optional.owner', '0007:asset/雪');
        $post = new PostCommand(surface: $s->handle->surface, body: '', audience: $s->post()->audience, publish: true, media: [$ref]);
        $media = new MediaAdapter($s->connection());
        $safety = new SafetyAdapter($s->connection());
        $s->app->instance(MediaResolver::class, $media);
        $s->app->instance(ContentSafety::class, $safety);
        $command = $s->command($post);
        if ($mode === 'safety-unknown') {
            $safety->decision = SafetyDecision::Unknown;
        } elseif ($mode === 'safety-error') {
            $safety->fail = true;
        } elseif ($mode !== 'allow') {
            $media->decision = $mode;
        }
        $result = $s->posts->create(handle: $s->handle, post: $post, command: $command);
        if ($mode === 'allow') {
            expect($result->isPublished())->toBeTrue();
            $resource = $result->operation->resource ?? throw new RuntimeException('No stored media post');
            expect($s->posts->read(handle: $s->handle, resource: $resource)->media)->toEqual([$ref]);
            expect($s->posts->revision(handle: $s->handle, resource: $resource, revision: new Revision(1))->media)->toEqual([$ref]);
        } else {
            expect($result->operation->isSuccess())->toBeFalse()->and($s->counts())->toBe(['posts' => 0, 'revisions' => 0, 'receipts' => 0, 'outbox' => 0]);
        }
        foreach ([...$safety->levels, ...$media->levels] as $level) {
            expect($level)->toBe(0);
        }
    } finally {
        $s->cleanup();
    }
})->with(['allow', 'safety-unknown', 'safety-error', 'unknown', 'error']);

it('publishes a pending revision once and keeps the first publication time through later edits', function (): void {
    $s = new PostScenario;
    try {
        $draft = $s->post(publish: false);
        $created = $s->posts->create(handle: $s->handle, post: $draft, command: $s->command($draft));
        expect($created->publication)->toBe(PublicationState::Pending);
        $ref = $created->operation->resource ?? throw new RuntimeException('Pending ref missing');
        $post = $s->post(body: 'Publish revision', resource: $ref);
        $command = $s->command(post: $post, expected: new Revision(1), key: 'post.publish.request.0001');
        $result = $s->posts->edit(handle: $s->handle, post: $post, command: $command);
        expect($result->isPublished())->toBeTrue()->and($result->operation->revision?->value)->toBe(2);
        $published = $s->posts->read(handle: $s->handle, resource: $ref)->publishedAt;
        expect($published)->not->toBeNull();
        expect($s->posts->edit(handle: $s->handle, post: $post, command: $command)->operation->revision?->value)->toBe(2);
        $again = $s->post(body: 'Publish again', resource: $ref);
        expect($s->posts->edit(handle: $s->handle, post: $again, command: $s->command(post: $again, expected: new Revision(2), key: 'post.publish.request.0002'))->isPublished())->toBeTrue();
        expect($s->posts->read(handle: $s->handle, resource: $ref)->publishedAt)->toEqual($published);
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 3, 'receipts' => 3, 'outbox' => 3]);
        $types = $s->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'posts.%'")->orderBy('id')->pluck('envelope')->map(fn ($wire): mixed => Scenario::object(json_decode(Scenario::text($wire), true, flags: JSON_THROW_ON_ERROR))['event_type'])->all();
        expect($types)->toBe(['posts.pending', 'posts.published', 'posts.edited']);
    } finally {
        $s->cleanup();
    }
});

it('uses trusted service onBehalfOf and denies a different author even with a host action grant', function (): void {
    $s = new PostScenario;
    try {
        $actor = new ActorContext(actor: new ActorRef('service', '0007:institution'), kind: ActorKind::Service,
            installation: $s->handle->actor->installation, ageBand: AgeBand::Adult, provenance: ContextProvenance::Direct,
            onBehalfOf: new ActorRef('school', '07'), generation: 'consent-v1');
        $s->fake->actors->actingAs($actor);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        $s->fake->surfaces->allow(viewer: $actor, surface: $s->handle->surface);
        $s->fake->surfaces->containment(actor: $actor, surface: $s->handle->surface, proposed: $s->post()->audience ?? throw new RuntimeException('audience'), decision: AuthorizationDecision::allow('fixture.service_audience'));
        $result = $s->posts->create(handle: $s->handle, post: $s->post(), command: $s->command());
        expect($result->isPublished())->toBeTrue();
        expect($s->connection()->table('panel_a.a_posts')->value('author_ref'))->toBe($actor->actor?->toWire());
        $receipt = $s->connection()->table('panel_a.a_receipts')->where('namespace', 'like', '%/posts.%')->first();
        expect($receipt?->actor_kind)->toBe('service')->and($receipt?->on_behalf_of)->toBe('school:07');
        $ref = $result->operation->resource ?? throw new RuntimeException('service post missing');
        $other = RuntimeScenario::actor();
        $s->fake->actors->actingAs($other);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        $edit = $s->post(resource: $ref);
        $command = new CommandEnvelope(key: new CommandKey('post.spoofed.request.0001'), namespace: new CommandNamespace(installation: $s->handle->actor->installation, panel: $s->handle->surface->panel, integration: 'host.client', commandType: 'posts.edit'), digest: PayloadDigest::of(commandType: 'posts.edit', payload: []), expectedRevision: new Revision(1));
        expect($s->posts->edit(handle: $s->handle, post: $edit, command: $command)->operation->problem)->toBe(ProblemCode::AuthorizationDenied);
        expect($s->counts()['revisions'])->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('keeps unknown age private by default and enforces minor max despite an adult public floor', function (): void {
    $s = new PostScenario(privacy: ['floor' => ['allowed_scopes' => ['direct', 'public'], 'default_scope' => 'public', 'max_scope' => 'public', 'minor_default_scope' => 'direct', 'minor_max_scope' => 'direct', 'contour_levels' => []]]);
    try {
        $trusted = $s->handle->actor;
        $actor = new ActorContext(actor: $trusted->actor, kind: $trusted->kind, installation: $trusted->installation, ageBand: AgeBand::Unknown, provenance: $trusted->provenance, generation: 'unknown-age');
        $s->fake->actors->actingAs($actor);
        $s->handle = $s->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        $pending = new PostCommand(surface: $s->handle->surface, body: 'Private default', publish: true);
        expect($s->posts->create(handle: $s->handle, post: $pending, command: $s->command($pending))->isPublished())->toBeTrue();
        $stored = json_decode(Scenario::text($s->connection()->table('panel_a.a_posts')->value('audience')), true, flags: JSON_THROW_ON_ERROR);
        expect($stored)->toEqual($s->post()->audience?->toWire());
        $public = $s->post(audience: AudienceSpec::public());
        $s->fake->surfaces->containment(actor: $actor, surface: $s->handle->surface, proposed: $public->audience ?? throw new RuntimeException('audience'), decision: AuthorizationDecision::allow('fixture.public_contained'));
        $result = $s->posts->create(handle: $s->handle, post: $public, command: $s->command(post: $public, key: 'post.minor.public.0001'));
        expect($result->operation->isSuccess())->toBeFalse()->and($s->counts()['posts'])->toBe(1);
    } finally {
        $s->cleanup();
    }
});

it('binds pending audience intent to its digest and never exposes a successful duplicate effect', function (): void {
    $s = new PostScenario;
    try {
        $draft = $s->post(publish: false);
        $command = $s->command($draft);
        $first = $s->posts->create(handle: $s->handle, post: $draft, command: $command);
        expect($first->publication)->toBe(PublicationState::Pending);
        $changed = $s->post(publish: false, audience: AudienceSpec::direct(new ActorRef('user', '8')));
        expect($s->command($changed)->digest)->not->toEqual($command->digest);
        $result = $s->posts->create(handle: $s->handle, post: $changed, command: $s->command($changed));
        expect($result->operation->isSuccess())->toBeFalse()->and($result->operation->resource)->toBeNull();
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 1, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});

it('does not expose a held historical body after a different clean revision is published', function (): void {
    $s = new PostScenario;
    try {
        $safety = new SafetyAdapter($s->connection(), SafetyDecision::Hold);
        $s->app->instance(ContentSafety::class, $safety);
        $held = $s->posts->create(handle: $s->handle, post: $s->post(), command: $s->command());
        $ref = $held->operation->resource ?? throw new RuntimeException('Held reference missing');
        $safety->decision = SafetyDecision::Allow;
        $clean = $s->post(body: 'Different clean body', resource: $ref);
        expect($s->posts->edit(handle: $s->handle, post: $clean, command: $s->command(post: $clean, expected: new Revision(1), key: 'post.clean.request.0001'))->isPublished())->toBeTrue();
        expect($s->posts->read(handle: $s->handle, resource: $ref)->body)->toBe('Different clean body');
        expect(fn () => $s->posts->revision(handle: $s->handle, resource: $ref, revision: new Revision(1)))->toThrow(PostException::class);
    } finally {
        $s->cleanup();
    }
});
