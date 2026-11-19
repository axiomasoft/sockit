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
use SocKit\Modules\Modules;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\OperationHandles;
use SocKit\Operations\Operations;
use SocKit\Panels\PanelDefinitions;
use SocKit\Reactions\Data\ReactionCommand;
use SocKit\Reactions\Reactions;
use SocKit\Reactions\ReactionsServiceProvider;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Schema\SnapshotRenderer;
use SocKit\SocKitServiceProvider;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\TargetRef;
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

final class ReactionScenario
{
    public Application $app;

    public RuntimeDatabase $db;

    public SocKitFake $fake;

    public EnvelopeTarget $target;

    public EnvelopeAuthority $authority;

    public EnvelopeAuthorityFence $fence;

    public OperationHandle $handle;

    public Reactions $reactions;

    public Operations $operations;

    private readonly mixed $previousDb;

    /** @var array<string, string> */
    private readonly array $sentinels;

    public function __construct(bool $install = true, bool $feature = true, bool $module = true)
    {
        $this->app = new Application;
        $this->app->instance('config', new Repository(['sockit' => [
            'panels' => [ReactionPanel::class, OtherReactionPanel::class], 'surfaces' => [ReactionSurface::class, OtherReactionSurface::class],
            'modules' => ['enabled' => ['sockit.reactions']],
        ]]));
        $this->app->instance(ReactionPanel::class, new ReactionPanel(enabled: $module));
        $this->app->instance(ReactionSurface::class, new ReactionSurface(enabled: $feature && $module));
        $this->app->register(SocKitServiceProvider::class);
        $this->app->register(ReactionsServiceProvider::class);
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
        $this->reactions = $this->app->make(Reactions::class);
        $this->operations = $this->app->make(Operations::class);
        foreach (['acme.a', 'acme.b'] as $panel) {
            $this->fake->surfaces->allow(viewer: RuntimeScenario::actor(), surface: RuntimeScenario::surface($panel));
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
            $this->db->decoy->exec('CREATE TABLE public.p42_sentinel (value text NOT NULL)');
            $this->db->decoy->exec("INSERT INTO public.p42_sentinel VALUES ('decoy-sentinel')");
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
            fwrite(STDERR, "P4.2 production reactions-only snapshots installed after owned PG proof; public surface API; no posts provider/tables\n");
        }
        $this->sentinels = $this->sentinels();
    }

    public function connection(string $id = 'a'): Connection
    {
        return $this->db->capsule->getConnection('owner_'.$id);
    }

    public function reaction(string $kind = 'like', string $target = '42', int $incarnation = 1): ReactionCommand
    {
        return new ReactionCommand(surface: $this->handle->surface, target: new TargetRef(host: new HostEntityRef(type: 'article', value: $target), incarnation: new Incarnation($incarnation)), kind: $kind);
    }

    public function command(string $action = 'add', ?Revision $expected = null, string $key = 'reaction.request.0001', ?ReactionCommand $reaction = null): CommandEnvelope
    {
        return new CommandEnvelope(key: new CommandKey($key), namespace: new CommandNamespace(installation: $this->handle->actor->installation, panel: $this->handle->surface->panel, integration: 'host.client', commandType: 'reactions.'.$action),
            digest: $this->reactions->digest(handle: $this->handle, reaction: $reaction ?? $this->reaction(), action: $action, expectedRevision: $expected), expectedRevision: $expected);
    }

    public function execute(?CommandEnvelope $command = null, ?ReactionCommand $reaction = null, string $action = 'add'): OperationResult
    {
        return $action === 'remove'
            ? $this->reactions->remove(handle: $this->handle, reaction: $reaction ?? $this->reaction(), command: $command ?? $this->command(action: $action))
            : $this->reactions->add(handle: $this->handle, reaction: $reaction ?? $this->reaction(), command: $command ?? $this->command(action: $action));
    }

    /** @return array<string, string> */
    private function sentinels(): array
    {
        $result = [];
        foreach (['surface_instances', 'reactions', 'reaction_counts', 'receipts', 'outbox', 'tombstones'] as $entity) {
            $result[$entity] = Scenario::text(PgTarget::query($this->db->owner, "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,'[]') FROM panel_b.other_b_{$entity} t")->fetchColumn());
        }
        $result['decoy'] = Scenario::text(PgTarget::query($this->db->decoy, 'SELECT value FROM public.p42_sentinel')->fetchColumn());

        return $result;
    }

    /** @return array{memberships: int, active: int, revision: int, count: int, counter_revision: int, receipts: int, outbox: int} */
    public function counts(): array
    {
        expect($this->sentinels())->toBe($this->sentinels);
        $result = [];
        foreach (['memberships' => 'count(*) FROM panel_a.driver_a_reactions', 'active' => 'count(*) FROM panel_a.driver_a_reactions WHERE active',
            'revision' => 'coalesce(max(revision),0) FROM panel_a.driver_a_reactions', 'count' => 'coalesce(sum(count),0) FROM panel_a.driver_a_reaction_counts',
            'counter_revision' => 'coalesce(max(revision),0) FROM panel_a.driver_a_reaction_counts',
            'receipts' => "count(*) FROM panel_a.driver_a_receipts WHERE namespace LIKE '%/reactions.%'", 'outbox' => "count(*) FROM panel_a.driver_a_outbox WHERE envelope::jsonb->>'event_type' LIKE 'reactions.%'"] as $key => $sql) {
            $result[$key] = (int) PgTarget::query($this->db->owner, 'SELECT '.$sql)->fetchColumn();
        }
        expect(PgTarget::query($this->db->owner, 'SELECT count(*) FROM panel_a.driver_a_receipts WHERE outcome IS NULL')->fetchColumn())->toBe(0);
        fwrite(STDERR, 'P4.2 exact SQL cardinalities '.json_encode($result, JSON_THROW_ON_ERROR)."; B and decoy unchanged\n");

        return $result;
    }

    public function cleanup(): void
    {
        $this->db->decoy->exec('DROP TABLE public.p42_sentinel');
        $this->db->cleanup();
        if ($this->previousDb !== null) {
            DB::swap($this->previousDb);
        } else {
            DB::clearResolvedInstance('db');
        }
        $this->app->flush();
    }
}
