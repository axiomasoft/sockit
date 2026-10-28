<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Operations\Contracts\MissingReceiptPolicy;
use SocKit\Operations\Contracts\MutationAuthorityFence;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Operations;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Tests\Integration\Support\PgTarget;

final class EnvelopeScenario
{
    public readonly OutboxScenario $base;

    public readonly EnvelopeTarget $target;

    public readonly EnvelopeMutation $mutation;

    public readonly EnvelopeAuthorityFence $fence;

    public readonly EnvelopeMissingReceiptPolicy $absence;

    public readonly Operations $operations;

    public readonly OperationHandle $handle;

    /** @var array<string, string> */
    private readonly array $sentinels;

    public function __construct(bool $install = true)
    {
        gc_collect_cycles();
        $this->base = new OutboxScenario(install: $install);
        $app = $this->base->app;
        $this->target = new EnvelopeTarget($this->base->db->capsule->getConnection('owner_a'));
        $app->instance(abstract: TargetResolver::class, instance: $this->target);
        // Real evaluator plus explicit host admission; no permissive audience fake in acceptance.
        $evaluator = $app->make(AudienceEvaluator::class);
        $app->instance(abstract: AudienceResolver::class, instance: $evaluator);
        $app->instance(abstract: AudienceGate::class, instance: new AudienceGate(resolver: $evaluator, surfacePolicy: $this->base->fake->surfaces));
        $this->base->fake->authorizer->allow(EnvelopeMutation::TYPE);
        foreach (['get', 'create', 'archive', 'resume', 'delete'] as $action) {
            $this->base->fake->authorizer->allow('sockit.surfaces.'.$action);
        }
        $this->handle = $this->base->handle();
        $this->operations = $app->make(Operations::class);
        $this->mutation = new EnvelopeMutation($this->base->db->capsule->getConnection('owner_a'));
        $this->fence = new EnvelopeAuthorityFence($this->base->db->capsule->getConnection('owner_a'));
        $app->instance(abstract: MutationAuthorityFence::class, instance: $this->fence);
        $this->absence = new EnvelopeMissingReceiptPolicy($app);
        $app->instance(abstract: MissingReceiptPolicy::class, instance: $this->absence);
        if ($install) {
            $pdo = $this->base->db->owner;
            foreach (['panel_a.driver_a_', 'panel_b.other_b_'] as $prefix) {
                $pdo->exec('DROP TABLE '.$prefix.'receipts');
                $pdo->exec('CREATE TABLE '.$prefix.'receipts (id uuid PRIMARY KEY, namespace varchar(384) COLLATE "C" NOT NULL, command_key varchar(128) COLLATE "C" NOT NULL, digest text NOT NULL, actor_ref text NOT NULL, actor_kind text NOT NULL, on_behalf_of text, outcome text, resource jsonb, revision bigint, problem text, guard_context text NOT NULL, UNIQUE (namespace,command_key))');
            }
            $pdo->exec('CREATE TABLE panel_a.driver_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
            $pdo->exec('INSERT INTO panel_a.driver_envelope_authority (allowed) VALUES (true)');
            $pdo->exec('CREATE TABLE panel_a.driver_envelope_targets (id text PRIMARY KEY, incarnation bigint NOT NULL, generation text NOT NULL, privacy bigint NOT NULL, visible boolean NOT NULL, lifecycle text NOT NULL, held boolean NOT NULL)');
            $pdo->exec("INSERT INTO panel_a.driver_envelope_targets VALUES ('42',1,'1',1,true,'live',false),('43',1,'1',1,true,'live',false)");
            $pdo->exec('CREATE TABLE panel_a.driver_envelope_state (target text PRIMARY KEY, effects bigint NOT NULL)');
            $pdo->exec("INSERT INTO panel_a.driver_envelope_state VALUES ('42',0),('43',0)");
            $pdo->exec('CREATE TABLE panel_a.driver_envelope_revisions (target text PRIMARY KEY, resource text NOT NULL, revision bigint NOT NULL)');
            $pdo->exec("INSERT INTO panel_a.driver_envelope_revisions SELECT '42',id,1 FROM panel_a.driver_a_surface_instances");
            $pdo->exec("INSERT INTO panel_a.driver_envelope_revisions SELECT '43',id,1 FROM panel_a.driver_a_surface_instances");
            fwrite(STDERR, "P2.7 all acme/schema DDL AFTER verified isolated identities\n");
        }
        $this->sentinels = $this->sentinels();
    }

    /** @return array<string, string> */
    private function sentinels(): array
    {
        $snapshots = [];
        foreach ([[$this->base->db->owner, 'panel_b.other_b_'], [$this->base->db->decoy, 'panel_a.driver_a_']] as [$pdo, $prefix]) {
            foreach (['surface_instances', 'receipts', 'outbox', 'tombstones'] as $entity) {
                $rows = PgTarget::query($pdo, 'SELECT row_to_json(t)::text FROM '.$prefix.$entity.' t')->fetchAll(\PDO::FETCH_COLUMN);
                sort($rows);
                $snapshots[$prefix.$entity] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
            }
        }

        return $snapshots;
    }

    public function command(string $key = 'req.2026-10-28.0001', ?Revision $expected = new Revision(1)): CommandEnvelope
    {
        return new CommandEnvelope(key: new CommandKey($key), namespace: new CommandNamespace(installation: $this->handle->actor->installation, panel: $this->handle->surface->panel, integration: 'acme.client', commandType: $this->mutation->commandType()),
            digest: $this->operations->digest(handle: $this->handle, mutation: $this->mutation, expectedRevision: $expected), expectedRevision: $expected);
    }

    public function execute(?CommandEnvelope $command = null): OperationResult
    {
        return $this->operations->execute(handle: $this->handle, command: $command ?? $this->command(), mutation: $this->mutation);
    }

    /** @return array{effects: int, revision: int, receipts: int, outbox: int} */
    public function counts(): array
    {
        expect($this->sentinels())->toBe($this->sentinels);
        $pdo = $this->base->db->owner;
        $result = ['effects' => (int) PgTarget::query($pdo, "SELECT effects FROM panel_a.driver_envelope_state WHERE target='42'")->fetchColumn(),
            'revision' => (int) PgTarget::query($pdo, "SELECT revision FROM panel_a.driver_envelope_revisions WHERE target='42'")->fetchColumn(),
            'receipts' => (int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_receipts')->fetchColumn(),
            'outbox' => (int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_outbox')->fetchColumn()];
        expect((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_b.other_b_receipts')->fetchColumn())->toBe(0)
            ->and((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_b.other_b_outbox')->fetchColumn())->toBe(0)
            ->and((int) PgTarget::query($pdo, 'SELECT revision FROM panel_b.other_b_surface_instances')->fetchColumn())->toBe(1)
            ->and((int) PgTarget::query($pdo, 'SELECT count(*) FROM panel_a.driver_a_receipts WHERE outcome IS NULL')->fetchColumn())->toBe(0);
        SurfaceScenario::decoyUntouched($this->base->db);
        fwrite(STDERR, 'P2.7 SQL assertions '.json_encode($result, JSON_THROW_ON_ERROR).' B revision=1/receipts=0/outbox=0 decoy=sentinel'.PHP_EOL);

        return $result;
    }

    public function receiptReads(): int
    {
        return count(array_filter($this->base->db->queries, static fn (array $q): bool => str_starts_with($q['sql'], 'select') && str_contains($q['sql'], 'a_receipts')));
    }

    public function cleanup(): void
    {
        $this->base->cleanup();
        $this->base->app->flush();
    }
}
