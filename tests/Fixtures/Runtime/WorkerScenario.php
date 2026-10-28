<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Foundation\Application;
use SocKit\Actors\ActorContexts;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationJobReference;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\OperationJobs;
use SocKit\Operations\Operations;
use SocKit\Panels\PanelDefinitions;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Testing\SocKitFake;
use SocKit\Tests\Integration\Support\PgTarget;

final class WorkerScenario
{
    public readonly EnvelopeScenario $base;

    public readonly Application $a;

    public readonly Application $b;

    public readonly RuntimeDatabase $dbB;

    public readonly WorkerActorResolver $actorA;

    public readonly WorkerActorResolver $actorB;

    public readonly SocKitFake $fakeB;

    public function __construct()
    {
        $this->base = new EnvelopeScenario;
        $this->a = $this->base->base->app;
        $this->b = OutboxScenario::app();
        $this->fakeB = RuntimeScenario::trust($this->b);
        $this->dbB = new RuntimeDatabase($this->b);
        $pdo = $this->base->base->db->owner;
        foreach (['authority', 'targets', 'state', 'revisions'] as $table) {
            $pdo->exec('CREATE TABLE panel_b.other_envelope_'.$table.' (LIKE panel_a.driver_envelope_'.$table.' INCLUDING ALL)');
            $pdo->exec('INSERT INTO panel_b.other_envelope_'.$table.' SELECT * FROM panel_a.driver_envelope_'.$table);
        }
        $pdo->exec('UPDATE panel_b.other_envelope_revisions SET resource=(SELECT id FROM panel_b.other_b_surface_instances)');
        foreach (['panel_a.driver_', 'panel_b.other_'] as $prefix) {
            $pdo->exec('CREATE TABLE '.$prefix.'worker_actors (id text PRIMARY KEY, generation text NOT NULL, age text NOT NULL)');
            $pdo->exec('INSERT INTO '.$prefix."worker_actors VALUES ('7','1','adult'),('8','1','adult')");
        }
        $this->actorA = $this->configure(app: $this->a, db: $this->base->base->db, fake: $this->base->base->fake, principal: '7', schema: 'panel_a', connection: 'owner_a', panel: 'acme.a');
        $this->actorB = $this->configure(app: $this->b, db: $this->dbB, fake: $this->fakeB, principal: '8', schema: 'panel_b', connection: 'owner_b', panel: 'acme.b');
    }

    private function configure(Application $app, RuntimeDatabase $db, SocKitFake $fake, string $principal, string $schema, string $connection, string $panel): WorkerActorResolver
    {
        $sql = $db->capsule->getConnection($connection);
        $resolver = new WorkerActorResolver(connection: $sql, schema: $schema);
        $app->instance(abstract: ActorContexts::class, instance: new ActorContexts(resolver: $resolver, tenants: $fake->tenants));
        $app->instance(abstract: TargetResolver::class, instance: new EnvelopeTarget(connection: $sql, schema: $schema));
        $app->instance(abstract: MutationAuthorityFence::class, instance: new EnvelopeAuthorityFence(connection: $sql, schema: $schema));
        $app->instance(abstract: ActionAuthorizer::class, instance: new EnvelopeAuthority(connection: $sql, schema: $schema));
        $evaluator = $app->make(AudienceEvaluator::class);
        $app->instance(abstract: AudienceResolver::class, instance: $evaluator);
        $app->instance(abstract: AudienceGate::class, instance: new AudienceGate(resolver: $evaluator, surfacePolicy: $fake->surfaces));
        $fake->surfaces->allow(viewer: RuntimeScenario::actor($principal), surface: RuntimeScenario::surface($panel));

        $fake->surfaces->allow(viewer: RuntimeScenario::actor('8'), surface: RuntimeScenario::surface($panel));

        return $resolver;
    }

    public function mutation(bool $b = false): EnvelopeMutation
    {
        return new EnvelopeMutation(connection: ($b ? $this->dbB : $this->base->base->db)->capsule->getConnection($b ? 'owner_b' : 'owner_a'), schema: $b ? 'panel_b' : 'panel_a');
    }

    public function job(bool $b = false, string $key = 'worker.original.1', int $revision = 1, ?string $principal = null): OperationJobReference
    {
        $app = $b ? $this->b : $this->a;
        $surface = RuntimeScenario::surface($b ? 'acme.b' : 'acme.a');
        $actor = $app->make(ActorContexts::class)->forWorker(RuntimeScenario::actor($principal ?? ($b ? '8' : '7'))->actor ?? throw new \LogicException);
        $handle = new OperationHandle(surface: $surface, actor: $actor, panel: $app->make(PanelDefinitions::class)->get($surface->panel), definition: $app->make(SurfaceDefinitions::class)->get(panel: $surface->panel, kind: $surface->kind));
        $mutation = $this->mutation($b);
        $command = new CommandEnvelope(key: new CommandKey($key), namespace: new CommandNamespace(installation: $actor->installation, panel: $surface->panel, integration: 'acme.worker', commandType: $mutation->commandType()),
            digest: $app->make(Operations::class)->digest(handle: $handle, mutation: $mutation, expectedRevision: new Revision($revision)), expectedRevision: new Revision($revision));
        $job = new OperationJobReference(surface: $surface, target: $mutation->request($handle)->target ?? throw new \LogicException, actor: $actor->actor ?? throw new \LogicException, sourceGeneration: '1', command: $command);

        return OperationJobReference::fromWire($job->toWire());
    }

    public function run(OperationJobReference $job, bool $b = false): OperationResult
    {
        return new OperationJobs($b ? $this->b : $this->a)->execute(job: $job, factory: fn (OperationHandle $handle): EnvelopeMutation => $this->mutation($b));
    }

    /** @return array{effects:int,revision:int,receipts:int,outbox:int} */
    public function counts(bool $b = false): array
    {
        $schema = $b ? 'panel_b.other_' : 'panel_a.driver_';
        $prefix = $schema.($b ? 'b_' : 'a_');
        $pdo = $this->base->base->db->owner;
        $result = ['effects' => (int) PgTarget::query($pdo, 'SELECT effects FROM '.$schema."envelope_state WHERE target='42'")->fetchColumn(),
            'revision' => (int) PgTarget::query($pdo, 'SELECT revision FROM '.$schema."envelope_revisions WHERE target='42'")->fetchColumn(),
            'receipts' => (int) PgTarget::query($pdo, 'SELECT count(*) FROM '.$prefix.'receipts')->fetchColumn(),
            'outbox' => (int) PgTarget::query($pdo, 'SELECT count(*) FROM '.$prefix.'outbox')->fetchColumn()];
        SurfaceScenario::decoyUntouched($this->base->base->db);
        fwrite(STDERR, 'P2.9 SQL '.($b ? 'B' : 'A').' '.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);

        return $result;
    }

    public function cleanup(): void
    {
        $this->base->cleanup();
        $this->dbB->cleanup();
        $this->b->flush();
    }
}
