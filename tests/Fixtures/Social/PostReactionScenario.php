<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Actors\ActorContexts;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Posts;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Reactions\Reactions;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;

/** Real production modules composed through their public APIs on the already proven owned PG target. */
final readonly class PostReactionScenario
{
    public PostScenario $s;

    public Reactions $reactions;

    public PostLifecycle $life;

    public OutsideActor $actors;

    /** @param array<string, mixed> $feed */
    public function __construct(bool $install = true, bool $posts = true, array $feed = [])
    {
        $this->s = new PostScenario(install: $install, module: $posts, reactions: true, feed: $feed);
        $this->actors = new OutsideActor(connection: $this->s->connection(), delegate: $this->s->fake->actors);
        $this->s->app->instance(ActorContexts::class, new ActorContexts(resolver: $this->actors, tenants: $this->s->fake->tenants));
        $this->s->app->forgetInstance(OperationHandles::class);
        $this->reactions = $this->s->app->make(Reactions::class);
        $this->life = new PostLifecycle($this->s);
    }

    public function actor(ActorContext $actor): void
    {
        $this->s->fake->actors->actingAs($actor);
        $this->s->fake->surfaces->allow(viewer: $actor, surface: $this->s->handle->surface);
        $this->s->handle = $this->s->app->make(OperationHandles::class)->forSurface($this->s->handle->surface);
    }

    public function create(bool $publish = true, string $key = 'post.request.0001'): ResourceRef
    {
        $audience = AudienceSpec::direct(RuntimeScenario::actor()->actor ?? throw new \RuntimeException('Owner'), RuntimeScenario::actor('8')->actor ?? throw new \RuntimeException('Viewer'));
        $this->s->fake->surfaces->containment(actor: $this->s->handle->actor, surface: $this->s->handle->surface, proposed: $audience, decision: AuthorizationDecision::allow('fixture.exact_set'));
        $post = $this->s->post(publish: $publish, audience: $audience);
        $result = $this->s->app->make(Posts::class)->create(handle: $this->s->handle, post: $post, command: $this->s->command(post: $post, key: $key));
        expect($result->operation->isSuccess())->toBeTrue();

        return $result->operation->resource ?? throw new \RuntimeException('Missing created post');
    }

    public function target(ResourceRef $resource, int $incarnation = 1, string $alias = 'sockit.post'): TargetRef
    {
        return new TargetRef(host: new HostEntityRef(type: $alias, value: $resource->id), incarnation: new Incarnation($incarnation));
    }

    public function reaction(TargetRef $target): ReactionCommand
    {
        return new ReactionCommand(surface: $this->s->handle->surface, target: $target, kind: 'like');
    }

    public function command(TargetRef $target, string $key = 'reaction.post.request.0001', ?Revision $expected = null, string $action = 'add'): CommandEnvelope
    {
        return new CommandEnvelope(key: new CommandKey($key), namespace: new CommandNamespace(installation: $this->s->handle->actor->installation, panel: $this->s->handle->surface->panel, integration: 'host.client', commandType: 'reactions.'.$action),
            digest: $this->reactions->digest(handle: $this->s->handle, reaction: $this->reaction($target), action: $action, expectedRevision: $expected), expectedRevision: $expected);
    }

    public function add(TargetRef $target, ?CommandEnvelope $command = null): OperationResult
    {
        return $this->reactions->add(handle: $this->s->handle, reaction: $this->reaction($target), command: $command ?? $this->command($target));
    }

    /** @return array{members: int, active: int, count: int, receipts: int, outbox: int} */
    public function counts(): array
    {
        $c = $this->s->connection();
        $counts = ['members' => $c->table('panel_a.a_reactions')->count(), 'active' => $c->table('panel_a.a_reactions')->where('active', true)->count(),
            'count' => (int) $c->table('panel_a.a_reaction_counts')->sum('count'),
            'receipts' => $c->table('panel_a.a_receipts')->where('namespace', 'like', '%/reactions.%')->count(),
            'outbox' => $c->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'reactions.%'")->count()];
        expect($c->table('panel_a.a_receipts')->whereNull('outcome')->count())->toBe(0)->and($c->transactionLevel())->toBe(0);
        expect(array_unique($this->actors->levels))->toBe([0]);
        $this->s->assertSentinels(); // Proves panel B and the decoy remained unchanged.
        fwrite(STDERR, 'P4.6 exact reaction SQL '.json_encode($counts, JSON_THROW_ON_ERROR).PHP_EOL);

        return $counts;
    }
}
