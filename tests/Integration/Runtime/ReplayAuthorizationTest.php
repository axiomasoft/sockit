<?php

declare(strict_types=1);

use SocKit\Actors\ActorContexts;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Contracts\MissingReceiptPolicy;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Internal\Storage\OwnerStorage;
use SocKit\Operations\OperationHandles;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\Revision;
use SocKit\Testing\FakeAudienceResolver;
use SocKit\Testing\FakeTenantResolver;
use SocKit\Tests\Fixtures\Runtime\EnvelopeMutation;
use SocKit\Tests\Fixtures\Runtime\EnvelopeScenario;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Runtime\UnavailableActor;
use SocKit\Tests\Fixtures\Social\PostLifecycle;
use SocKit\Tests\Fixtures\Social\PostScenario;

it('reauthorizes execute replay and lookup after current revoke removal visibility generation or lifecycle change', function (string $case): void {
    $s = new EnvelopeScenario;
    try {
        $command = $s->command();
        expect($s->execute($command)->isSuccess())->toBeTrue();
        $db = $s->base->db->owner;
        if ($case === 'revoke') {
            $s->base->fake->authorizer->deny(action: EnvelopeMutation::TYPE, reason: 'fixture.revoked');
        }
        if ($case === 'unknown_action') {
            $s->base->fake->authorizer->unknown(EnvelopeMutation::TYPE);
        }
        if ($case === 'actor_removed') {
            $s->base->app->instance(abstract: ActorContexts::class, instance: new ActorContexts(resolver: new UnavailableActor, tenants: $s->base->fake->tenants));
        }
        if ($case === 'other_actor') {
            $actor = RuntimeScenario::actor('8');
            $s->base->fake->actors->actingAs($actor);
            $s->base->fake->surfaces->allow(viewer: $actor, surface: $s->handle->surface);
        }
        if ($case === 'installation') {
            $s->base->app->instance(abstract: ActorContexts::class, instance: new ActorContexts(resolver: $s->base->fake->actors, tenants: new FakeTenantResolver(new InstallationRef('another'))));
        }
        if (in_array($case, ['actor_removed', 'installation'], true)) {
            $s->base->app->forgetInstance(OperationHandles::class);
            $s->base->app->forgetInstance(OwnerStorage::class);
        }
        if ($case === 'held') {
            $db->exec("UPDATE panel_a.driver_envelope_targets SET held=true WHERE id='42'");
        }
        if ($case === 'hidden') {
            $db->exec("UPDATE panel_a.driver_envelope_targets SET visible=false WHERE id='42'");
        }
        if ($case === 'deleted') {
            $db->exec("UPDATE panel_a.driver_envelope_targets SET lifecycle='deleted' WHERE id='42'");
        }
        if ($case === 'unknown_target') {
            $db->exec("UPDATE panel_a.driver_envelope_targets SET lifecycle='unknown' WHERE id='42'");
        }
        if ($case === 'generation') {
            $db->exec("UPDATE panel_a.driver_envelope_targets SET generation='2' WHERE id='42'");
        }
        if ($case === 'privacy') {
            $db->exec("UPDATE panel_a.driver_envelope_targets SET privacy=2 WHERE id='42'");
        }
        if ($case === 'incarnation') {
            $db->exec("UPDATE panel_a.driver_envelope_targets SET incarnation=2 WHERE id='42'");
        }
        if ($case === 'surface_deleted') {
            $db->exec("UPDATE panel_a.driver_a_surface_instances SET lifecycle='deleted',deleted_at=now(),updated_at=now()");
        }
        if ($case === 'surface_incarnation') {
            $db->exec('UPDATE panel_a.driver_a_surface_instances SET incarnation=2');
        }
        if ($case === 'deny_admission') {
            $s->base->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface, reason: 'audience.denied.not_in_contour');
        }
        $replay = $s->execute($command);
        $lookup = $s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
        expect($replay->isSuccess())->toBeFalse()->and($replay->resource)->toBeNull()->and($lookup)->not->toBeNull()->and($lookup?->resource)->toBeNull()
            ->and($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
})->with(['revoke', 'unknown_action', 'actor_removed', 'other_actor', 'installation', 'held', 'hidden', 'deleted', 'unknown_target', 'generation', 'privacy', 'incarnation', 'surface_deleted', 'surface_incarnation', 'deny_admission']);

it('allows a currently readable read-only replay without giving another write grant', function (string $case): void {
    $s = new EnvelopeScenario;
    try {
        $command = $s->command();
        $first = $s->execute($command);
        expect($first->isSuccess())->toBeTrue();
        if ($case === 'target') {
            $s->base->db->owner->exec("UPDATE panel_a.driver_envelope_targets SET lifecycle='read_only' WHERE id='42'");
        }
        if ($case === 'admission') {
            $s->base->fake->surfaces->allowReadOnly(viewer: $s->handle->actor, surface: $s->handle->surface);
        }
        if ($case === 'archive') {
            $s->base->db->owner->exec("UPDATE panel_a.driver_a_surface_instances SET lifecycle='archived'");
        }
        expect($s->execute($command)->toWire())->toBe($first->toWire());
        $new = $s->command(key: 'req.2026-10-28.new2', expected: new Revision(2));
        expect($s->execute($new)->isSuccess())->toBeFalse()->and($s->counts()['effects'])->toBe(1)->and($s->counts()['outbox'])->toBe(1);
    } finally {
        $s->cleanup();
    }
})->with(['target', 'admission', 'archive']);

it('protects absent lookup and does not reveal another actor namespace or panel winner', function (): void {
    $s = new EnvelopeScenario;
    try {
        $command = $s->command();
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key))->toBeNull();
        expect($s->execute($command)->isSuccess())->toBeTrue();
        $other = RuntimeScenario::actor('8');
        $s->base->fake->actors->actingAs($other);
        $s->base->fake->surfaces->allow(viewer: $other, surface: $s->handle->surface);
        $handle = $s->base->app->make(OperationHandles::class)->forSurface($s->handle->surface);
        expect($s->operations->lookup(handle: $handle, namespace: $command->namespace, key: $command->key))->toBeNull();
        $foreign = new CommandNamespace(installation: new InstallationRef('foreign'), panel: $handle->surface->panel, integration: 'acme.client', commandType: EnvelopeMutation::TYPE);
        expect($s->operations->lookup(handle: $handle, namespace: $foreign, key: $command->key)?->resource)->toBeNull();
        $s->base->fake->authorizer->deny(action: EnvelopeMutation::TYPE, reason: 'fixture.denied');
        expect($s->operations->lookup(handle: $handle, namespace: $command->namespace, key: new CommandKey('req.2026-10-28.absent'))?->problem)->toBe(ProblemCode::AuthorizationDenied);
    } finally {
        $s->cleanup();
    }
});

it('binds same-panel keys to the full target incarnation surface and CAS payload before claim', function (string $case): void {
    $s = new EnvelopeScenario;
    try {
        $command = $s->command();
        expect($s->execute($command)->isSuccess())->toBeTrue();
        if ($case === 'target') {
            $s->mutation->targetKey = '43';
        }
        if ($case === 'incarnation') {
            $s->mutation->incarnation = 2;
        }
        if ($case === 'payload_ref') {
            $s->mutation->extraPayload = ['surface' => RuntimeScenario::surface('acme.b')->toWire()];
        }
        $result = $s->execute($command);
        expect($result->problem)->toBe(ProblemCode::PayloadInvalid)->and($result->resource)->toBeNull();
        if ($case === 'target') {
            $different = $s->execute($s->command());
            expect($different->problem)->toBe(ProblemCode::IdempotencyConflict)->and($different->resource)->toBeNull();
        }
        expect($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
})->with(['target', 'incarnation', 'payload_ref']);

it('records only definitive safe rejections and never unknown decisions', function (string $case): void {
    $s = new EnvelopeScenario;
    try {
        if ($case === 'deny') {
            $s->base->fake->authorizer->deny(action: EnvelopeMutation::TYPE, reason: 'fixture.denied');
        }
        if ($case === 'unknown') {
            $s->base->fake->authorizer->unknown(EnvelopeMutation::TYPE);
        }
        if ($case === 'safety') {
            $s->mutation->safety = AuthorizationDecision::deny('fixture.hold');
        }
        if ($case === 'safety_unknown') {
            $s->mutation->safety = AuthorizationDecision::unknown('fixture.unknown');
        }
        $result = $s->execute();
        expect($result->isSuccess())->toBeFalse()->and($result->resource)->toBeNull()->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => in_array($case, ['deny', 'safety'], true) ? 1 : 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['deny', 'unknown', 'safety', 'safety_unknown']);

it('does not let a custom allow resolver bypass admission or current unrelated containment', function (string $case): void {
    $s = new EnvelopeScenario;
    try {
        $resolver = new FakeAudienceResolver;
        $resolver->verdict(key: 'acme.subject', verdict: AudienceVerdict::allow('audience.allowed.platform'));
        $s->base->app->instance(abstract: AudienceGate::class, instance: new AudienceGate(resolver: $resolver, surfacePolicy: $s->base->fake->surfaces));
        if ($case === 'admission') {
            $s->base->fake->surfaces->deny(viewer: $s->handle->actor, surface: $s->handle->surface, reason: 'audience.denied.not_in_contour');
        } else {
            $s->mutation->proposed = $case === 'group' ? AudienceSpec::group(new GroupRef('unrelated')) : AudienceSpec::contour(level: 'class', contour: new HostEntityRef(type: 'class', value: 'other'));
            $s->base->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $s->mutation->proposed,
                decision: $case === 'unknown' ? AuthorizationDecision::unknown('fixture.unprovable') : AuthorizationDecision::deny('fixture.unrelated'));
        }
        expect($s->execute()->isSuccess())->toBeFalse()->and($s->counts()['effects'])->toBe(0)->and($s->counts()['outbox'])->toBe(0);
    } finally {
        $s->cleanup();
    }
})->with(['admission', 'class', 'group', 'unknown']);

it('publishes an explicitly contained direct audience through the real evaluator', function (): void {
    $s = new EnvelopeScenario;
    try {
        $s->mutation->proposed = AudienceSpec::direct($s->handle->actor->actor ?? throw new RuntimeException('actor required'));
        $s->base->fake->surfaces->containment(actor: $s->handle->actor, surface: $s->handle->surface, proposed: $s->mutation->proposed, decision: AuthorizationDecision::allow('fixture.contained'));
        expect($s->execute()->isSuccess())->toBeTrue()->and($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});

it('rejects command type and supplied author or cross-panel authority before any claim', function (string $case): void {
    $s = new EnvelopeScenario;
    try {
        $command = $s->command();
        if ($case === 'author') {
            $s->mutation->extraPayload = ['author' => 'user:999'];
        }
        if ($case === 'type') {
            $command = new CommandEnvelope(key: $command->key, namespace: new CommandNamespace(installation: $command->namespace->installation, panel: $command->namespace->panel, integration: 'acme.client', commandType: 'acme.other.mutate'), digest: $command->digest, expectedRevision: $command->expectedRevision);
        }
        if ($case === 'panel') {
            $command = new CommandEnvelope(key: $command->key, namespace: new CommandNamespace(installation: $command->namespace->installation, panel: RuntimeScenario::surface('acme.b')->panel, integration: 'acme.client', commandType: EnvelopeMutation::TYPE), digest: $command->digest, expectedRevision: $command->expectedRevision);
        }
        expect($s->execute($command)->isSuccess())->toBeFalse()->and($s->mutation->writes)->toBe(0)
            ->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
})->with(['author', 'type', 'panel']);

it('looks up an existing outcome using its declared action even when commandType is denied and absent mapping differs', function (): void {
    $s = new EnvelopeScenario;
    try {
        $s->mutation->authorizationAction = 'acme.content.edit';
        $s->base->fake->authorizer->allow('acme.content.edit')->deny(action: EnvelopeMutation::TYPE, reason: 'fixture.command_is_not_action');
        $command = $s->command();
        $original = $s->execute($command);
        expect($original->isSuccess())->toBeTrue();
        $s->absence->actions[EnvelopeMutation::TYPE] = 'acme.content.unrelated';
        $readsBefore = $s->receiptReads();
        $result = $s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
        expect($result?->toWire())->toBe($original->toWire())->and($s->absence->calls)->toBe(0)
            ->and($s->receiptReads())->toBeGreaterThan($readsBefore);
        $s->base->fake->authorizer->deny(action: 'acme.content.edit', reason: 'fixture.revoked')->allow(EnvelopeMutation::TYPE);
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->problem)->toBe(ProblemCode::AuthorizationDenied)
            ->and($s->counts())->toBe(['effects' => 1, 'revision' => 2, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});

it('authorizes absent outcomes only through an explicit action mapping consistent with execute', function (): void {
    $s = new EnvelopeScenario;
    try {
        $s->mutation->authorizationAction = 'acme.content.edit';
        $s->absence->actions[EnvelopeMutation::TYPE] = 'acme.content.edit';
        $s->base->fake->authorizer->allow('acme.content.edit')->deny(action: EnvelopeMutation::TYPE, reason: 'fixture.command_is_not_action');
        $command = $s->command();
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key))->toBeNull()->and($s->absence->calls)->toBe(1);
        $s->base->fake->authorizer->deny(action: 'acme.content.edit', reason: 'fixture.revoked')->allow(EnvelopeMutation::TYPE);
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->problem)->toBe(ProblemCode::AuthorizationDenied);
        unset($s->absence->actions[EnvelopeMutation::TYPE]);
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->problem)->toBe(ProblemCode::AuthorizationUnknown)
            ->and($s->counts())->toBe(['effects' => 0, 'revision' => 1, 'receipts' => 0, 'outbox' => 0]);
    } finally {
        $s->cleanup();
    }
});

it('requires an absence policy only for missing module receipts and still reads an existing receipt through its own guards', function (): void {
    $s = new EnvelopeScenario;
    try {
        $command = $s->command();
        $s->base->app->offsetUnset(MissingReceiptPolicy::class);
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->problem)->toBe(ProblemCode::ProviderUnavailable);
        $result = $s->execute($command);
        expect($result->isSuccess())->toBeTrue()->and($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->toWire())->toBe($result->toWire());
    } finally {
        $s->cleanup();
    }
});

it('protects a committed resource reference in generic lookup after a current owner hold', function (): void {
    $s = new PostScenario;
    try {
        $command = $s->command();
        $result = $s->posts->create(handle: $s->handle, post: $s->post(), command: $command);
        expect($result->isPublished())->toBeTrue(json_encode($result, JSON_THROW_ON_ERROR));
        $s->connection()->table('panel_a.a_posts')->update(['publication_state' => 'held']);
        $lookup = $s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
        expect($lookup?->resource)->toBeNull()->and($lookup?->revision)->toBeNull()->and($lookup?->isSuccess())->toBeFalse();
    } finally {
        $s->cleanup();
    }
});

it('binds content events to the committed owner revision while preserving the original host replay fence', function (): void {
    $s = new PostScenario;
    try {
        $created = $s->posts->create(handle: $s->handle, post: $s->post(), command: $s->command());
        $ref = $created->operation->resource ?? throw new RuntimeException('Missing post');
        $edit = $s->post(body: 'Content revision two', resource: $ref);
        $command = $s->command(post: $edit, expected: new Revision(1), key: 'content.owner.revision.0001');
        expect($s->posts->edit(handle: $s->handle, post: $edit, command: $command)->isPublished())->toBeTrue();
        $event = new PostLifecycle($s)->event(2);
        expect($event['source_revision'])->toBe(2)->and($event['privacy_revision'])->toBe(2)
            ->and($event['payload'])->toEqual(['post' => $ref->toWire()]);
        expect($s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key)?->isSuccess())->toBeTrue();
        $s->connection()->table('panel_a.envelope_targets')->where('id', '42')->update(['privacy' => 2]);
        $lookup = $s->operations->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
        expect($lookup?->problem)->toBe(ProblemCode::AuthorizationUnknown)->and($lookup?->resource)->toBeNull()->and($lookup?->revision)->toBeNull();
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 2, 'receipts' => 2, 'outbox' => 2]);
    } finally {
        $s->cleanup();
    }
});
