<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\GroupAudienceResolver;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\OperationHandles;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Posts;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Reactions\Reactions;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\SurfaceInstances;
use SocKit\Tests\Fixtures\Runtime\EnvelopeAuthorityFence;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;

final class ContentScenario
{
    public MembershipScenario $membership;

    public GroupScenario $f;

    public ResourceRef $group;

    public OperationHandle $handle;

    public Posts $posts;

    public Reactions $reactions;

    public AudienceGate $gate;

    public function __construct(bool $install = true, ?ResourceRef $group = null, bool $active = true, string $kind = 'acme.club')
    {
        $this->membership = new MembershipScenario(content: true, install: $install, contentKind: $kind);
        $this->f = $this->membership->f;
        $this->f->app->instance(TargetResolver::class, new ContentHostTarget($this->f->connection(), $this->f->target));
        $resolver = new GroupAudienceResolver($this->f->app, $this->f->app->make(ContourResolver::class));
        $this->f->app->instance(AudienceResolver::class, $resolver);
        $this->gate = new AudienceGate($resolver, $this->f->fake->surfaces);
        $this->f->app->instance(AudienceGate::class, $this->gate);
        $this->group = $group ?? $this->membership->create(JoinPolicy::Auto);
        if ($install && $active) {
            expect($this->membership->run($this->group, 'join')->isSuccess())->toBeTrue();
        }
        $surface = new SurfaceRef($this->group->panel, new SurfaceKindId($kind), new HostEntityRef('sockit.group', $this->group->id), new Incarnation(1));
        $actor = RuntimeScenario::actor();
        $this->f->fake->surfaces->allow($actor, $surface);
        foreach ([AudienceSpec::direct($actor->actor ?? throw new \RuntimeException), $this->audience()] as $spec) {
            $this->f->fake->surfaces->containment($actor, $surface, $spec, AuthorizationDecision::allow('fixture.exact_content_boundary'));
        }
        $this->handle = $this->f->app->make(OperationHandles::class)->forSurface($surface);
        $this->posts = $this->f->app->make(Posts::class);
        $this->reactions = $this->f->app->make(Reactions::class);
        if ($install) {
            $surfaces = $this->f->app->make(SurfaceInstances::class);
            $command = new CommandEnvelope(new CommandKey('group.content.create.0001'), new CommandNamespace($actor->installation, $surface->panel, 'host.client', 'sockit.surfaces.create'),
                $surfaces->digest($this->handle, 'create'));
            expect($surfaces->create($this->handle, $command)->isSuccess())->toBeTrue();
        }
    }

    public function audience(): AudienceSpec
    {
        return AudienceSpec::group(new GroupRef($this->group->id));
    }

    public function subject(string $key = 'content', ?SurfaceRef $surface = null, ?AudienceSpec $spec = null): AudienceSubject
    {
        return new AudienceSubject($key, $spec ?? $this->audience(), $this->handle->actor->actor, $surface ?? $this->handle->surface, $this->handle->actor->installation);
    }

    public function post(?AudienceSpec $audience = null): PostCommand
    {
        return new PostCommand(surface: $this->handle->surface, body: 'Group post', audience: $audience ?? $this->audience(), publish: true);
    }

    public function command(PostCommand $post, string $key = 'group.post.request.0001', ?Revision $revision = null): CommandEnvelope
    {
        return new CommandEnvelope(new CommandKey($key), new CommandNamespace($this->handle->actor->installation, $this->handle->surface->panel, 'host.client',
            $post->resource === null ? 'posts.create' : 'posts.edit'), $this->posts->digest($this->handle, $post, $revision), $revision);
    }

    public function reaction(ResourceRef $post, string $key = 'group.reaction.0001'): CommandEnvelope
    {
        $reaction = $this->reactionInput($post);

        return new CommandEnvelope(new CommandKey($key), new CommandNamespace($this->handle->actor->installation, $this->handle->surface->panel, 'host.client', 'reactions.add'),
            $this->reactions->digest($this->handle, $reaction, 'add'));
    }

    public function reactionInput(ResourceRef $post): ReactionCommand
    {
        return new ReactionCommand($this->handle->surface, new TargetRef(new HostEntityRef('sockit.post', $post->id), new Incarnation(1)));
    }

    public function createSurface(OperationHandle $handle, string $key): void
    {
        $surfaces = $this->f->app->make(SurfaceInstances::class);
        $command = new CommandEnvelope(new CommandKey($key), new CommandNamespace($handle->actor->installation, $handle->surface->panel,
            'host.client', 'sockit.surfaces.create'), $surfaces->digest($handle, 'create'));
        expect($surfaces->create($handle, $command)->isSuccess())->toBeTrue();
    }

    public function anotherGroup(): ResourceRef
    {
        $original = $this->f->handle;
        $surface = new SurfaceRef($original->surface->panel, $original->surface->kind, new HostEntityRef('article', '43'), new Incarnation(1));
        $actor = $original->actor;
        $this->f->fake->surfaces->allow($actor, $surface)->containment($actor, $surface,
            AudienceSpec::direct($actor->actor ?? throw new \RuntimeException), AuthorizationDecision::allow('fixture.exact_direct'));
        $this->f->handle = $this->f->app->make(OperationHandles::class)->forSurface($surface);
        try {
            $this->createSurface($this->f->handle, 'another.management.surface.0001');
            $group = $this->membership->create(JoinPolicy::Auto);
            expect($this->membership->run($group, 'join')->isSuccess())->toBeTrue();

            return $group;
        } finally {
            $this->f->handle = $original;
        }
    }

    public function neighbor(): SurfaceRef
    {
        $surface = RuntimeScenario::surface('acme.b');
        $actor = $this->handle->actor;
        $this->f->fake->surfaces->allow($actor, $surface)->containment($actor, $surface,
            AudienceSpec::direct($actor->actor ?? throw new \RuntimeException), AuthorizationDecision::allow('fixture.exact_direct'));
        $connection = $this->f->connection('b');
        $connection->statement('CREATE TABLE panel_b.other_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
        $connection->table('panel_b.envelope_authority')->insert(['allowed' => true]);
        $this->f->app->instance(MutationAuthorityFence::class,
            new EnvelopeAuthorityFence($connection, 'panel_b'));
        try {
            $this->createSurface($this->f->app->make(OperationHandles::class)->forSurface($surface), 'neighbor.management.surface.0001');
        } finally {
            $this->f->app->instance(MutationAuthorityFence::class, $this->f->fence);
        }

        return $surface;
    }

    /** @return list<string> */
    public function locks(): array
    {
        return array_values(array_filter(array_column($this->f->db->queries, 'sql'), static fn (string $sql): bool => str_contains($sql, 'for update')));
    }

    public function cleanup(): void
    {
        $this->membership->cleanup();
    }

    public function management(string $action): OperationResult
    {
        $surfaces = $this->f->app->make(SurfaceInstances::class);
        $handle = $this->f->handle;
        $revision = $surfaces->get($handle)->revision;
        $command = new CommandEnvelope(new CommandKey('management.race.'.$action.'.0001'), new CommandNamespace($handle->actor->installation,
            $handle->surface->panel, 'host.client', 'sockit.surfaces.'.$action), $surfaces->digest($handle, $action, $revision), $revision);

        return match ($action) {
            'archive' => $surfaces->archive($handle, $command),
            'delete' => $surfaces->delete($handle, $command),
            default => throw new \RuntimeException('Unsupported management transition'),
        };
    }

    /** @return array<string, int> */
    public function contentEffects(): array
    {
        $result = [];
        foreach (['posts', 'post_revisions', 'reactions', 'reaction_counts'] as $entity) {
            $result[$entity] = $this->f->connection()->table('panel_a.a_'.$entity)->count();
        }
        $result['outbox'] = $this->f->connection()->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'posts.%' OR envelope::jsonb->>'event_type' LIKE 'reactions.%'")->count();

        return $result;
    }
}
