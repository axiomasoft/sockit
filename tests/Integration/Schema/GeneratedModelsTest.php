<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\ModelBindings;
use SocKit\Schema\ModelEmitter;
use SocKit\Schema\PanelSchema;
use SocKit\Support\Ids;
use SocKit\Tests\Fixtures\Schema\GeneratedModelFixture;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;

it('autoloads emitted consumer models and preserves real CRUD hydration replication and FK relations', function (): void {
    $f = new GeneratedModelFixture;
    try {
        foreach (['a', 'b', 'a'] as $panel) {
            $class = $f->model(panel: $panel);
            foreach (['42', '0042'] as $key) {
                $model = $class::query()->firstOrCreate(attributes: ['installation' => 'host', 'panel' => 'acme.'.$panel, 'external_key' => $key]);
                expect($model->getKey())->toBeString()->toMatch('/^[a-f0-9-]{14}7/')->and($model->getIncrementing())->toBeFalse();
                $model->setAttribute('enabled', false);
                $model->save();
                $hydrated = GeneratedModelFixture::instance($class::query()->findOrFail(GeneratedModelFixture::key($model)));
                expect($hydrated->getAttribute('external_key'))->toBe($key)->and($hydrated->getAttribute('enabled'))->toBeFalse()
                    ->and($hydrated->getTable())->toBe('panel_'.$panel.'.'.$panel.'_demo_targets')->and($hydrated->getConnectionName())->toBe('owner_'.$panel);
                $link = $f->model(entity: 'demo_links', panel: $panel)::query()->create(['target_id' => $model->getKey()]);
                expect(GeneratedModelFixture::instance($link->getRelationValue('target'))->getKey())->toBe($model->getKey())
                    ->and(GeneratedModelFixture::instance(GeneratedModelFixture::many($hydrated->getRelationValue('demoLinksTarget'))->last())->getKey())->toBe($link->getKey());
                expect($hydrated->newInstance()->getTable())->toBe($hydrated->getTable())
                    ->and($hydrated->newFromBuilder($hydrated->getAttributes())->getConnectionName())->toBe('owner_'.$panel);
                $link->save();
                $link->delete();
                $revision = $f->model(entity: 'demo_revisions', panel: $panel)::query()->create(['target_id' => $model->getKey(), 'revision' => 1, 'payload' => ['external_key' => $key], 'created_at' => '2026-10-29 00:00:00+00']);
                expect(GeneratedModelFixture::instance($revision->getRelationValue('target'))->getKey())->toBe($model->getKey());
                $revision->setAttribute('payload', ['external_key' => $key, 'edited' => true]);
                $revision->save();
                expect(GeneratedModelFixture::instance($revision->fresh())->getAttribute('created_at'))->toBeInstanceOf(CarbonImmutable::class);
                $revision->delete();
                $copy = $hydrated->replicate();
                $copy->setAttribute('external_key', 'copy-'.$key.'-'.Ids::uuid7());
                $copy->save();
                expect($copy->getKey())->not->toBe($hydrated->getKey())->and($copy->getConnectionName())->toBe('owner_'.$panel);
                $copy->delete();
                expect($class::query()->find($copy->getKey()))->toBeNull();
            }
        }
        $surface = $f->model('surface_instances')::query()->create(['installation' => 'host', 'panel' => 'acme.a', 'kind' => 'acme.runtime', 'host_type' => 'article', 'host_key' => '0042',
            'incarnation' => 1, 'revision' => 1, 'source_generation' => 1, 'privacy_generation' => 1, 'lifecycle' => 'live']);
        expect(GeneratedModelFixture::instance($surface->fresh())->getAttribute('created_at'))->toBeInstanceOf(CarbonImmutable::class);
        $receipt = $f->model('receipts')::query()->create(['namespace' => 'host/acme.a/test/write', 'command_key' => 'model.command.0001', 'digest' => 'sha256:'.str_repeat('0', 64), 'actor_ref' => 'user:0042', 'actor_kind' => 'person', 'guard_context' => '{}']);
        $receipt->setAttribute('outcome', 'confirmed');
        $receipt->setAttribute('resource', ['panel' => 'acme.a', 'key' => '0042']);
        $receipt->setAttribute('revision', 1);
        $receipt->save();
        expect(GeneratedModelFixture::instance($receipt->fresh())->getAttribute('resource'))->toEqual(['panel' => 'acme.a', 'key' => '0042']);
        $outbox = $f->model('outbox')::query()->create(['installation' => 'host', 'panel' => 'acme.a', 'kind' => 'acme.runtime', 'host_type' => 'article', 'host_key' => '0042',
            'incarnation' => 1, 'source_generation' => '1', 'event_id' => Ids::uuid7(), 'digest' => 'sha256:'.str_repeat('0', 64), 'envelope' => '{}', 'deliveries' => (object) []]);
        expect(GeneratedModelFixture::instance($outbox->fresh())->getAttribute('deliveries'))->toBe([]);
        $outbox->setAttribute('deliveries', ['sink' => 'pending']);
        $outbox->save();
        expect(GeneratedModelFixture::instance($outbox->fresh())->getAttribute('deliveries'))->toBe(['sink' => 'pending']);
        $surface->setAttribute('lifecycle', 'archived');
        $surface->save();
        foreach ([$outbox, $surface, $receipt] as $writable) {
            $writable->delete();
            expect($writable->fresh())->toBeNull();
        }
        $f->snapshot->untouched();
        expect($f->snapshot->capsule->getDatabaseManager()->getDefaultConnection())->toBe('decoy');
        fwrite(STDERR, 'P3.4 SQL assertions emitted_crud_hydration_replication=true fk_inverse_relations=true uuid7_string=true host_42_0042_lossless=true json_datetime_casts=true decoy_untouched=true'.PHP_EOL);
    } finally {
        $f->cleanup();
    }
});

it('validates custom class maps and rejects wrong bindings before use', function (): void {
    $f = new GeneratedModelFixture;
    try {
        $schema = $f->snapshot->schemas['a'];
        $original = $f->model();
        $expected = $original::binding();
        foreach (['valid', 'panel', 'entity', 'table', 'schema', 'connection', 'casts', 'key_policy', 'primary', 'key_type', 'incrementing', 'timestamps', 'abstract', 'foreign', 'missing'] as $change) {
            $binding = $expected;
            if (! in_array($change, ['valid', 'abstract', 'foreign', 'missing'], true)) {
                $binding[$change] = match ($change) {
                    'casts' => ['id' => 'integer'], 'primary' => ['other'], 'incrementing', 'timestamps' => true, default => 'wrong',
                };
            }
            $name = 'Custom'.str_replace('-', '', Ids::uuid7());
            $class = 'Consumer\\'.$name;
            if ($change !== 'missing') {
                $source = "<?php\nnamespace Consumer;\n".($change === 'abstract' ? 'abstract ' : '').'class '.$name.' extends \\'.($change === 'foreign' ? stdClass::class : $original).' { public const array BINDING = '.var_export($binding, true).'; }';
                $path = SnapshotFixture::file($source);
                require $path;
                unlink($path);
            }
            $storage = new PanelStorage(connection: $schema->storage->connection, schema: $schema->storage->schema, prefix: $schema->storage->prefix,
                tables: $schema->storage->tables, models: array_replace($schema->storage->models, ['demo_targets' => $class]));
            $custom = new PanelSchema(panel: $schema->panel, storage: $storage, components: [], entities: SchemaValues::map($schema->jsonSerialize()['entities']), order: []);
            if ($change === 'valid') {
                new ModelBindings()->validate($custom);
                expect(GeneratedModelFixture::instance(new $class)->getTable())->toBe('panel_a.a_demo_targets');
            } else {
                expect(fn () => new ModelBindings()->validate($custom))->toThrow(InvalidSchemaException::class);
            }
        }
        $model = new $original;
        expect(fn () => $model->setTable('panel_b.b_demo_targets'))->toThrow(InvalidSchemaException::class)
            ->and(fn () => $model->setConnection('owner_b'))->toThrow(InvalidSchemaException::class)
            ->and(fn () => $model->setKeyName('external_key'))->toThrow(InvalidSchemaException::class)
            ->and(fn () => $model->setKeyType('integer'))->toThrow(InvalidSchemaException::class)
            ->and(fn () => $model->setIncrementing(true))->toThrow(InvalidSchemaException::class);
        expect(new ModelEmitter()->render($schema))->toBe(new ModelEmitter()->render($schema));
        $entities = SchemaValues::map($schema->jsonSerialize()['entities']);
        $links = SchemaValues::map($entities['demo_links']);
        $fk = SchemaValues::map($links['foreign_keys']);
        $entities['demo_links'] = [...$links, 'foreign_keys' => ['target_one' => $fk['target'], 'target__one' => $fk['target']]];
        $collision = new PanelSchema(panel: $schema->panel, storage: $schema->storage, components: [], entities: $entities, order: []);
        expect(fn () => new ModelEmitter()->render($collision))->toThrow(InvalidSchemaException::class);

        fwrite(STDERR, 'P3.4 assertions custom_map_compatible=true wrong_binding_rejected=true immutable_table_connection=true'.PHP_EOL);
    } finally {
        $f->cleanup();
    }
});

it('exposes composite tombstones only through complete scoped reads and refuses single-key persistence and queues', function (): void {
    $f = new GeneratedModelFixture;
    try {
        $class = $f->model('tombstones');
        $identity = ['installation' => 'host', 'panel' => 'acme.a', 'kind' => 'acme.runtime', 'host_type' => 'article', 'host_key' => '0042', 'incarnation' => 1];
        $f->snapshot->connection()->table('panel_a.a_tombstones')->insert([...$identity, 'surface_id' => Ids::uuid7(), 'max_revision' => 2, 'source_generation' => 1, 'deleted_at' => '2026-10-29 00:00:00+00']);
        expect(($class::readIdentity($identity) ?? throw new RuntimeException('Missing tombstone'))['host_key'])->toBe('0042')->and($class::readIdentity(array_replace($identity, ['host_key' => '42'])))->toBeNull();
        $model = new $class;
        foreach ([fn () => $class::find('42'), fn () => $model->save(), $model->delete(...), $model->getQueueableId(...), fn () => $model->newQueryForRestoration('42'), fn () => $class::readIdentity(['host_key' => '0042']), fn () => $class::readIdentity(array_replace($identity, ['panel' => 'acme.b']))] as $call) {
            expect($call)->toThrow(InvalidSchemaException::class);
        }
        expect($f->snapshot->connection()->table('panel_a.a_tombstones')->count())->toBe(1);
        $f->snapshot->untouched();
        fwrite(STDERR, 'P3.4 SQL assertions composite_full_identity_read=true composite_find_save_delete_queue_denied=true no_fake_id=true'.PHP_EOL);
    } finally {
        $f->cleanup();
    }
});
