<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use SocKit\Actors\ActorContexts;
use SocKit\Operations\OperationJobs;
use SocKit\Panels\Panel;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\ModelEmitter;
use SocKit\Schema\PanelModel;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Support\Ids;
use SocKit\Tests\Fixtures\Runtime\WorkerScenario;
use SocKit\Tests\Fixtures\Schema\DemoTargetsV1;
use SocKit\Tests\Fixtures\Schema\GeneratedModelFixture;
use SocKit\Tests\Fixtures\Schema\ModelQueueJob;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

it('restores real Illuminate identifiers in fresh consumer processes and a long-lived A B A worker with nested relations and B exception', function (): void {
    $f = new GeneratedModelFixture;
    try {
        $ids = [];
        foreach (['a', 'b'] as $panel) {
            $target = $f->model(panel: $panel)::query()->create(['installation' => 'host', 'panel' => 'acme.'.$panel, 'external_key' => '0042']);
            $link = $f->model(entity: 'demo_links', panel: $panel)::query()->create(['target_id' => $target->getKey()]);
            $revision = $f->model(entity: 'demo_revisions', panel: $panel)::query()->create(['target_id' => $target->getKey(), 'revision' => 1, 'payload' => ['external_key' => '0042'], 'created_at' => '2026-10-29 00:00:00+00']);
            $ids[$panel] = GeneratedModelFixture::key($target);
            $target->load('demoRevisionsTarget.target');
            $surface = $f->model(entity: 'surface_instances', panel: $panel)::query()->create(['installation' => 'host', 'panel' => 'acme.'.$panel, 'kind' => 'acme.runtime', 'host_type' => 'article', 'host_key' => '0042',
                'incarnation' => 1, 'revision' => 1, 'source_generation' => 1, 'privacy_generation' => 1, 'lifecycle' => 'live']);
            $receipt = $f->model(entity: 'receipts', panel: $panel)::query()->create(['namespace' => 'host/acme.'.$panel.'/test/write', 'command_key' => 'queue.command.0001', 'digest' => 'sha256:'.str_repeat('0', 64), 'actor_ref' => 'user:0042', 'actor_kind' => 'person', 'guard_context' => '{}']);
            $outbox = $f->model(entity: 'outbox', panel: $panel)::query()->create(['installation' => 'host', 'panel' => 'acme.'.$panel, 'kind' => 'acme.runtime', 'host_type' => 'article', 'host_key' => '0042',
                'incarnation' => 1, 'source_generation' => '1', 'event_id' => Ids::uuid7(), 'digest' => 'sha256:'.str_repeat('0', 64), 'envelope' => '{}', 'deliveries' => (object) []]);
            foreach ([$target, $revision->load('target'), $link->load('target'), $surface, $receipt, $outbox] as $model) {
                $payload = serialize(new ModelQueueJob($model));
                expect($payload)->toContain(ModelIdentifier::class);
                $path = SnapshotFixture::file(json_encode(['files' => $f->files, 'payload' => base64_encode($payload)], JSON_THROW_ON_ERROR));
                try {
                    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Schema/model-queue-child.php', $path]);
                    $process->setTimeout(20);
                    $process->run();
                    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
                    $result = Scenario::object(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
                    expect($result['class'])->toBe($model::class)->and($result['id'])->toBe($model->getKey())->and($result['panel'])->toBe('acme.'.$panel)
                        ->and($result['connection'])->toBe('owner_'.$panel)->and($result['table'])->toBe($model->getTable())->and($result['default'])->toBe('decoy');
                    fwrite(STDERR, 'P3.4 fresh restore '.$process->getOutput());
                } finally {
                    unlink($path);
                }
            }
        }
        foreach (['a', 'b', 'a'] as $panel) {
            $original = GeneratedModelFixture::instance($f->model(panel: $panel)::query()->findOrFail($ids[$panel]))->load('demoRevisionsTarget.target');
            $payload = serialize(new ModelQueueJob($original));
            $restored = unserialize($payload);
            if (! $restored instanceof ModelQueueJob) {
                throw new RuntimeException('Missing restored job');
            }
            $model = $restored->model;
            expect($model->getConnectionName())->toBe('owner_'.$panel)->and($model->getAttribute('external_key'))->toBe('0042');
            $nestedValue = GeneratedModelFixture::instance(GeneratedModelFixture::many($model->getRelationValue('demoRevisionsTarget'))->first())->getRelationValue('target');
            $nested = GeneratedModelFixture::instance($nestedValue);
            expect($nested->getConnectionName())->toBe('owner_'.$panel)->and($nested->getKey())->toBe($ids[$panel]);
            if ($panel === 'b') {
                try {
                    $model->getConnection()->transaction(function () use ($model): void {
                        $model->setAttribute('external_key', 'rolled-back');
                        $model->save();
                        throw new RuntimeException('B job exception');
                    });
                } catch (RuntimeException $error) {
                    expect($error->getMessage())->toBe('B job exception');
                }
            } else {
                $model->setAttribute('enabled', false);
                $model->save();
            }
        }
        expect($f->model(panel: 'b')::query()->value('external_key'))->toBe('0042');
        $missing = GeneratedModelFixture::instance($f->model()::query()->findOrFail($ids['a']));
        $payload = serialize(new ModelQueueJob($missing));
        $f->model('demo_revisions')::query()->delete();
        $f->model('demo_links')::query()->delete();
        $missing->delete();
        expect(fn () => unserialize($payload))->toThrow(ModelNotFoundException::class);
        $codec = new class
        {
            use SerializesAndRestoresModelIdentifiers;
        };
        expect(fn () => $codec->restoreModel(new ModelIdentifier(class: $f->model(), id: $ids['a'], relations: [], connection: 'owner_b')))->toThrow(InvalidSchemaException::class);
        $f->snapshot->untouched();
        expect($f->snapshot->capsule->getDatabaseManager()->getDefaultConnection())->toBe('decoy');
        fwrite(STDERR, 'P3.4 SQL assertions fresh_bootstrap_all_single_key_entities=true A_B_A_nested_relations=true B_exception_rollback=true identifier_connection_tamper_denied=true decoy_untouched=true'.PHP_EOL);
    } finally {
        $f->cleanup();
    }
});

it('rechecks current actor and authorization after model restoration without fallback or effects', function (string $revocation): void {
    $s = new WorkerScenario;
    $previous = Model::getConnectionResolver();
    $paths = [];
    try {
        Model::setConnectionResolver($s->base->base->db->capsule->getDatabaseManager());
        $schema = new PanelSchemaCompiler()->compile(panel: (new Panel)->id('acme.a')->hostTypes('article')->connection('owner_a')->schema('panel_a')->prefix('a_')->modules('acme.demo-targets')->materialize(), components: ['acme.demo-targets' => new DemoTargetsV1()->component()]);
        foreach (new ModelEmitter()->render($schema) as $class => $source) {
            if (! class_exists($class, false)) {
                $paths[] = $path = SnapshotFixture::file($source);
                require $path;
            }
        }
        $s->base->base->db->owner->exec('CREATE TABLE panel_a.driver_a_demo_targets (id uuid PRIMARY KEY, installation text, panel text, external_key text, enabled boolean DEFAULT true)');
        $class = $schema->storage->models['demo_targets'];
        if (! is_a($class, PanelModel::class, true)) {
            throw new RuntimeException('Missing emitted class');
        }
        $model = $class::query()->create(['installation' => 'host', 'panel' => 'acme.a', 'external_key' => '0042']);
        $payload = serialize(new ModelQueueJob($model));
        $job = $s->job();
        if ($revocation === 'actor') {
            $s->base->base->db->owner->exec("DELETE FROM panel_a.driver_worker_actors WHERE id='7'");
        } else {
            $s->base->base->db->owner->exec('UPDATE panel_a.driver_envelope_authority SET allowed=false,generation=generation+1');
        }
        $restored = unserialize($payload);
        if (! $restored instanceof ModelQueueJob) {
            throw new RuntimeException('Missing restored job');
        }
        expect($restored)->toBeInstanceOf(ModelQueueJob::class)->and($restored->model->getAttribute('external_key'))->toBe('0042');
        $seen = null;
        $result = new OperationJobs($s->a)->execute(job: $job, factory: function ($handle) use ($s, &$seen) {
            $seen = $handle->actor;

            return $s->mutation();
        });
        expect($result->isSuccess())->toBeFalse()->and($result->resource)->toBeNull()->and($s->counts()['effects'])->toBe(0)
            ->and($s->counts()['outbox'])->toBe(0)->and($s->counts()['receipts'])->toBe($revocation === 'permission' ? 1 : 0);
        if ($revocation === 'actor') {
            expect($seen)->toBeNull();
        } else {
            expect($seen?->actor?->id)->toBe('7');
        }
        expect($s->a->make(ActorContexts::class)->current()->actor?->id)->toBe('caller');
        fwrite(STDERR, 'P3.4 SQL assertions model_restored=true revoked_'.$revocation.'_denied=true no_fallback_or_effects=true'.PHP_EOL);
    } finally {
        foreach ($paths as $path) {
            unlink($path);
        }
        $previous === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($previous);
        $s->cleanup();
    }
})->with(['actor', 'permission']);
