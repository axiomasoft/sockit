<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Modules\Modules;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Panels\PanelDefinitions;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Internal\PostsServiceProvider;
use SocKit\Posts\Posts;
use SocKit\Reactions\Internal\ReactionsServiceProvider;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Schema\SnapshotRenderer;
use SocKit\SocKitServiceProvider;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\SurfaceInstances;
use SocKit\Testing\SocKitFake;
use SocKit\Tests\Fixtures\Runtime\EnvelopeAuthority;
use SocKit\Tests\Fixtures\Runtime\EnvelopeAuthorityFence;
use SocKit\Tests\Fixtures\Runtime\EnvelopeTarget;
use SocKit\Tests\Fixtures\Runtime\RuntimeDatabase;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Runtime\SurfaceScenario;
use SocKit\Tests\Fixtures\Schema\LoadedSnapshot;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class PostScenario
{
    public Application $app;

    public RuntimeDatabase $db;

    public SocKitFake $fake;

    public EnvelopeTarget $target;

    public EnvelopeAuthority $authority;

    public EnvelopeAuthorityFence $fence;

    public OperationHandle $handle;

    public Posts $posts;

    public Operations $operations;

    private mixed $previousDb;

    /** @var array<string, string> */
    private array $sentinels;

    /** @param array<string, mixed> $privacy */
    public function __construct(bool $install = true, bool $feature = true, bool $module = true, array $privacy = [], bool $reactions = false)
    {
        $this->app = new Application;
        $this->app->instance('config', new Repository(['sockit' => [
            'panels' => [PostPanel::class, OtherPostPanel::class], 'surfaces' => [PostSurface::class, OtherPostSurface::class],
            'modules' => ['enabled' => ['sockit.posts', ...($reactions ? ['sockit.reactions'] : [])]],
            ...($privacy === [] ? [] : ['privacy' => $privacy]),
        ]]));
        $this->app->instance(PostPanel::class, new PostPanel(enabled: $module, reactions: $reactions));
        $this->app->instance(PostSurface::class, new PostSurface(enabled: $feature && $module, reactions: $reactions));
        $this->app->register(SocKitServiceProvider::class);
        $this->app->register(PostsServiceProvider::class);
        if ($reactions) {
            $this->app->register(ReactionsServiceProvider::class);
        }
        $this->app->boot();
        $this->db = new RuntimeDatabase($this->app); // Proves owned isolated target before any DDL.
        $this->previousDb = DB::getFacadeRoot();
        DB::swap($this->db->capsule->getDatabaseManager());
        $this->fake = RuntimeScenario::trust($this->app);
        $evaluator = $this->app->make(AudienceEvaluator::class);
        $this->app->instance(AudienceResolver::class, $evaluator);
        $this->app->instance(AudienceGate::class, new AudienceGate(resolver: $evaluator, surfacePolicy: $this->fake->surfaces));
        $this->target = new EnvelopeTarget($this->connection());
        $this->authority = new EnvelopeAuthority($this->connection());
        $this->fence = new EnvelopeAuthorityFence($this->connection());
        $this->app->instance(TargetResolver::class, $this->target);
        $this->app->instance(ActionAuthorizer::class, $this->authority);
        $this->app->instance(MutationAuthorityFence::class, $this->fence);
        $this->handle = $this->app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface());
        $this->posts = $this->app->make(Posts::class);
        $this->operations = $this->app->make(Operations::class);
        foreach (['acme.a', 'acme.b'] as $panel) {
            $this->fake->surfaces->allow(viewer: RuntimeScenario::actor(), surface: RuntimeScenario::surface($panel));
            $this->fake->surfaces->containment(actor: RuntimeScenario::actor(), surface: RuntimeScenario::surface($panel), proposed: AudienceSpec::direct(RuntimeScenario::actor()->actor ?? throw new \RuntimeException('actor')), decision: AuthorizationDecision::allow('fixture.exact_direct_set'));
        }
        if ($install) {
            foreach ($this->app->make(PanelDefinitions::class)->all() as $panel) {
                $id = $panel->id->value === 'acme.a' ? 'a' : 'b';
                $this->db->owner->exec('CREATE SCHEMA panel_'.$id);
                $connection = $this->connection($id);
                $schema = new PanelSchemaCompiler()->compile(panel: $panel, components: $this->app->make(Modules::class)->schemaComponents());
                $config = Scenario::object($connection->getConfig());
                $observed = PgTarget::query($this->db->owner, 'SELECT host(inet_server_addr()) AS host, inet_server_port() AS port')->fetch(\PDO::FETCH_ASSOC);
                $path = SnapshotFixture::file(new SnapshotRenderer()->render(schema: $schema, connection: $connection,
                    deployment: ['cluster' => 'owned_pg', 'endpoint' => ['host' => $config['host'], 'port' => $config['port']], 'aliases' => [$observed]]));
                try {
                    new LoadedSnapshot($path)->up();
                } finally {
                    unlink($path);
                }
            }
            $this->db->owner->exec('CREATE TABLE panel_a.driver_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
            $this->db->owner->exec('INSERT INTO panel_a.driver_envelope_authority (allowed) VALUES (true)');
            $this->db->owner->exec('CREATE TABLE panel_a.driver_envelope_targets (id text PRIMARY KEY, incarnation bigint NOT NULL, generation text NOT NULL, privacy bigint NOT NULL, visible boolean NOT NULL, lifecycle text NOT NULL, held boolean NOT NULL)');
            $this->db->owner->exec("INSERT INTO panel_a.driver_envelope_targets VALUES ('42',1,'1',1,true,'live',false),('43',1,'1',1,true,'live',false)");
            $this->db->decoy->exec('CREATE TABLE public.p44_sentinel (value text NOT NULL)');
            $this->db->decoy->exec("INSERT INTO public.p44_sentinel VALUES ('decoy-sentinel')");
            foreach (['acme.a', 'acme.b'] as $panel) {
                if ($panel === 'acme.b') {
                    SurfaceScenario::create(SurfaceScenario::owner($this->app, $panel));

                    continue;
                }
                $handle = $this->app->make(OperationHandles::class)->forSurface(RuntimeScenario::surface($panel));
                $surfaces = $this->app->make(SurfaceInstances::class);
                $command = new CommandEnvelope(key: new CommandKey('surface.creation.0001'), namespace: new CommandNamespace(installation: $handle->actor->installation, panel: $handle->surface->panel, integration: 'host.client', commandType: 'sockit.surfaces.create'), digest: $surfaces->digest(handle: $handle, action: 'create'));
                $created = $surfaces->create(handle: $handle, command: $command);
                if (! $created->isSuccess()) {
                    throw new \RuntimeException('Public surface create failed: '.json_encode($created));
                }
            }
            fwrite(STDERR, "P4.4 production posts snapshots installed after owned PG proof; public surface API\n");
        }
        $this->sentinels = $this->sentinels();
    }

    public function connection(string $id = 'a'): Connection
    {
        return $this->db->capsule->getConnection('owner_'.$id);
    }

    public function post(string $body = 'First post', bool $publish = true, ?ResourceRef $resource = null, ?AudienceSpec $audience = null): PostCommand
    {
        return new PostCommand(surface: $this->handle->surface, body: $body, audience: $audience ?? AudienceSpec::direct($this->handle->actor->actor ?? throw new \RuntimeException('actor missing')), publish: $publish, resource: $resource);
    }

    public function command(?PostCommand $post = null, ?Revision $expected = null, string $key = 'post.request.0001'): CommandEnvelope
    {
        $post ??= $this->post();

        return new CommandEnvelope(key: new CommandKey($key), namespace: new CommandNamespace(installation: $this->handle->actor->installation, panel: $this->handle->surface->panel, integration: 'host.client', commandType: $post->resource === null ? 'posts.create' : 'posts.edit'),
            digest: $this->posts->digest(handle: $this->handle, post: $post, expectedRevision: $expected), expectedRevision: $expected);
    }

    /** @return array<string, string> */
    private function sentinels(): array
    {
        $result = [];
        foreach (['surface_instances', 'posts', 'post_revisions', 'receipts', 'outbox', 'tombstones'] as $entity) {
            $result[$entity] = Scenario::text(PgTarget::query($this->db->owner, "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,'[]') FROM panel_b.other_b_{$entity} t")->fetchColumn());
        }
        $result['decoy'] = Scenario::text(PgTarget::query($this->db->decoy, 'SELECT value FROM public.p44_sentinel')->fetchColumn());

        return $result;
    }

    /** @return array{posts: int, revisions: int, receipts: int, outbox: int} */
    public function counts(): array
    {
        $this->assertSentinels();
        $c = $this->connection();

        return ['posts' => $c->table('panel_a.a_posts')->count(), 'revisions' => $c->table('panel_a.a_post_revisions')->count(),
            'receipts' => $c->table('panel_a.a_receipts')->where('namespace', 'like', '%/posts.%')->count(),
            'outbox' => $c->table('panel_a.a_outbox')->whereRaw("envelope::jsonb->>'event_type' LIKE 'posts.%'")->count()];
    }

    public function assertSentinels(): void
    {
        expect($this->sentinels())->toBe($this->sentinels);
    }

    public function cleanup(): void
    {
        $this->db->decoy->exec('DROP TABLE public.p44_sentinel');
        $this->db->cleanup();
        if ($this->previousDb !== null) {
            DB::swap($this->previousDb);
        } else {
            DB::clearResolvedInstance('db');
        }
        $this->app->flush();
    }
}
