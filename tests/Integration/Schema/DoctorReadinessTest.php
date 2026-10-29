<?php

declare(strict_types=1);

use SocKit\Schema\Internal\SchemaValues;
use SocKit\Tests\Fixtures\PanelConsumer\DoctorConsumer;
use SocKit\Tests\Fixtures\Schema\MigrationFixture;
use SocKit\Tests\Fixtures\Schema\UpgradeVariant;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('reports ready only for a fully installed compatible panel and accepts a nullable host column', function (): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $doctor = new DoctorConsumer($f);
        $result = $doctor->run();
        expect($result['exit'])->toBe(0, json_encode($result['data'], JSON_THROW_ON_ERROR))->and(DoctorConsumer::field($result, 'data', 'status'))->toBe('ready')
            ->and(DoctorConsumer::field($result, 'data', 'generated'))->toBe(1)->and(DoctorConsumer::field($result, 'data', 'applied'))->toBe(1)->and($result['sql'])->not->toBeEmpty();
        $f->pg->owner->exec('ALTER TABLE panel_a.driver_article_demo_targets ADD COLUMN host_note text');
        expect($doctor->run()['exit'])->toBe(0)->and($doctor->run(['json' => false])['output'])->toContain('acme.article: ready');
        fwrite(STDERR, "P3.7 real SQL ready + host extension; read-only guard, catalog/data/file digests unchanged\n");
    } finally {
        $f->cleanup();
    }
});

it('fails closed for missing and unknown selection with machine readable actions', function (string $panel): void {
    $f = new MigrationFixture(selected: true);
    try {
        $result = new DoctorConsumer($f)->run(['panel' => $panel]);
        expect($result['exit'])->toBe(2)->and(DoctorConsumer::field($result, 'data', 'problems', 0, 'code'))->toBe('PANEL_INVALID_OR_UNKNOWN')
            ->and(DoctorConsumer::field($result, 'data', 'problems', 0, 'action'))->not->toBeEmpty()->and($result['sql'])->toBe([]);
    } finally {
        $f->cleanup();
    }
})->with(['omit', 'unknown.panel', '!!!']);

it('distinguishes missing history, generated pending, and required upgrades', function (): void {
    $f = new MigrationFixture(selected: true);
    try {
        $doctor = new DoctorConsumer($f);
        expect(DoctorConsumer::field($doctor->run(), 'data', 'errors'))->toContain('SCHEMA_HISTORY_MISSING');
        expect($f->command()['exit'])->toBe(0);
        $pending = $doctor->run();
        expect($pending['exit'])->toBe(1)->and(DoctorConsumer::field($pending, 'data', 'errors'))->toContain('SCHEMA_GENERATED_PENDING');
        expect($f->migrate()['exit'])->toBe(0);
        $upgrade = $doctor->run(['version' => 2]);
        expect($upgrade['exit'])->toBe(1)->and(DoctorConsumer::field($upgrade, 'data', 'errors'))->toContain('SCHEMA_UPGRADE_REQUIRED');
        $f->boot(selected: true, version: 2);
        $f->repairModels();
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and(DoctorConsumer::field($doctor->run(['version' => 2]), 'data', 'errors'))->toContain('SCHEMA_GENERATED_PENDING');
        expect($f->migrate()['exit'])->toBe(0)->and($doctor->run(['version' => 2])['exit'])->toBe(0);
    } finally {
        $f->cleanup();
    }
});

it('rejects live catalog and ownership drift without repairing storage', function (string $damage, string $expected): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $physical = Scenario::object(Scenario::object(json_decode((string) file_get_contents($f->manifests()[0]), true, flags: JSON_THROW_ON_ERROR))['physical']);
        $entities = Scenario::object($physical['entities']);
        $target = Scenario::object($entities['demo_targets']);
        $surface = Scenario::object($entities['surface_instances']);
        $external = Scenario::text(Scenario::object(Scenario::object($target['indexes'])['external'])['name']);
        $primary = Scenario::text(Scenario::object($target['primary'])['name']);
        $positive = Scenario::text(Scenario::object(Scenario::object($surface['checks'])['revision_positive'])['name']);
        $sql = match ($damage) {
            'table' => 'DROP TABLE panel_a.driver_article_demo_targets',
            'column' => 'ALTER TABLE panel_a.driver_article_demo_targets DROP COLUMN enabled',
            'type' => 'ALTER TABLE panel_a.driver_article_demo_targets ALTER COLUMN external_key TYPE varchar(9)',
            'nullable' => 'ALTER TABLE panel_a.driver_article_demo_targets ALTER COLUMN external_key DROP NOT NULL',
            'default' => 'ALTER TABLE panel_a.driver_article_demo_targets ALTER COLUMN enabled SET DEFAULT false',
            'index' => 'DROP INDEX panel_a.'.$external,
            'index_shape' => 'DROP INDEX panel_a.'.$external.'; CREATE UNIQUE INDEX '.$external.' ON panel_a.driver_article_demo_targets (external_key)',
            'primary' => 'ALTER TABLE panel_a.driver_article_demo_targets DROP CONSTRAINT '.$primary,
            'check' => 'ALTER TABLE panel_a.driver_article_surface_instances DROP CONSTRAINT '.$positive,
            'check_shape' => 'ALTER TABLE panel_a.driver_article_surface_instances DROP CONSTRAINT '.$positive.'; ALTER TABLE panel_a.driver_article_surface_instances ADD CONSTRAINT '.$positive.' CHECK (revision >= 0)',
            'ownership' => "COMMENT ON TABLE panel_a.driver_article_demo_targets IS 'foreign'",
            'host_required' => 'ALTER TABLE panel_a.driver_article_demo_targets ADD COLUMN required_by_host text NOT NULL',
            'extra_unique' => 'CREATE UNIQUE INDEX host_unique ON panel_a.driver_article_demo_targets (external_key)',
            'collation' => 'ALTER TABLE panel_a.driver_article_demo_targets ALTER COLUMN installation TYPE varchar(64) COLLATE "default"',
            'rls' => 'ALTER TABLE panel_a.driver_article_demo_targets ENABLE ROW LEVEL SECURITY',
            default => throw new RuntimeException('Unknown damage'),
        };
        $f->pg->owner->exec($sql);
        $result = new DoctorConsumer($f)->run();
        expect($result['exit'])->toBe(1)->and(DoctorConsumer::field($result, 'data', 'errors'))->toContain($expected)
            ->and(DoctorConsumer::field($result, 'data', 'problems', 0))->toHaveKeys(['entity', 'component', 'step', 'path', 'action']);
        fwrite(STDERR, 'P3.7 SQL drift '.$damage.' -> '.$expected."; no diagnostic mutation\n");
    } finally {
        $f->cleanup();
    }
})->with([
    ['table', 'SCHEMA_TABLE_MISSING'], ['column', 'SCHEMA_COLUMN_MISSING'], ['type', 'SCHEMA_COLUMN_INCOMPATIBLE'],
    ['nullable', 'SCHEMA_COLUMN_INCOMPATIBLE'], ['default', 'SCHEMA_COLUMN_INCOMPATIBLE'], ['index', 'SCHEMA_INDEX_INCOMPATIBLE'],
    ['index_shape', 'SCHEMA_INDEX_INCOMPATIBLE'], ['primary', 'SCHEMA_CONSTRAINT_MISSING'], ['check', 'SCHEMA_CONSTRAINT_MISSING'],
    ['check_shape', 'SCHEMA_CONSTRAINT_INCOMPATIBLE'], ['ownership', 'SCHEMA_OWNERSHIP_CHANGED'],
    ['host_required', 'SCHEMA_HOST_COLUMN_REQUIRES_VALUE'], ['rls', 'SCHEMA_TABLE_INCOMPATIBLE'], ['extra_unique', 'SCHEMA_UNEXPECTED_UNIQUE_INDEX'], ['collation', 'SCHEMA_COLUMN_INCOMPATIBLE'],
]);

it('rejects tampered/missing source, ancestry, unsupported version, ledger, model and identity', function (string $damage, string $expected): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $manifest = $f->manifests()[0];
        $record = Scenario::object(json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR));
        $migration = Scenario::text($record['migration']);
        $options = [];
        if ($damage === 'file') {
            unlink($f->root.'/database/migrations/'.$migration.'.php');
        } elseif ($damage === 'history') {
            file_put_contents($manifest, '{}');
        } elseif ($damage === 'version') {
            $record['format_version'] = 99;
            unset($record['digest']);
            $record['digest'] = hash('sha256', SchemaValues::json($record));
            file_put_contents($manifest, SchemaValues::json($record));
        } elseif ($damage === 'gap') {
            rename($manifest, dirname($manifest).'/000002.json');
        } elseif ($damage === 'ledger') {
            $query = $f->pg->owner->prepare('INSERT INTO public.driver_migrations (migration,batch) VALUES (?,2)');
            $query->execute([substr($migration, 0, 18).'999999_sockit_'.substr(hash('sha256', 'acme.article'), 0, 16)]);
        } elseif ($damage === 'model' || $damage === 'cast') {
            $models = array_values(array_filter(array_keys($f->tree()), fn (string $path): bool => str_ends_with($path, '/DemoTarget.php')));
            $path = $f->root.'/'.$models[0];
            if ($damage === 'model') {
                unlink($path);
            } else {
                file_put_contents($path, str_replace("'enabled' => 'boolean'", "'enabled' => 'string'", (string) file_get_contents($path)));
            }
        } elseif ($damage === 'prefix') {
            $options['driverPrefix'] = 'wrong_';
        } elseif ($damage === 'identity') {
            $options['config'] = ['sockit.storage.deployments.owner_a.cluster' => 'different-cluster'];
        } else {
            $options['config'] = ['sockit.storage.deployments.owner_a' => null];
        }
        $result = new DoctorConsumer($f)->run($options);
        expect($result['exit'])->toBe(1)->and(DoctorConsumer::field($result, 'data', 'errors'))->toContain($expected);
        fwrite(STDERR, 'P3.7 source/identity '.$damage.' -> '.$expected."\n");
    } finally {
        $f->cleanup();
    }
})->with([
    ['file', 'MIGRATION_FILE_TAMPERED'], ['history', 'MIGRATION_HISTORY_TAMPERED'], ['version', 'MIGRATION_HISTORY_TAMPERED'],
    ['gap', 'MIGRATION_HISTORY_GAP_OR_BRANCH'], ['ledger', 'MIGRATION_LEDGER_NOT_PREFIX'], ['model', 'MODEL_BINDING_INVALID'], ['cast', 'MODEL_BINDING_INVALID'],
    ['prefix', 'SCHEMA_PHYSICAL_IDENTITY_CHANGED'], ['identity', 'SCHEMA_PHYSICAL_IDENTITY_CHANGED'], ['ambiguity', 'STORAGE_DEPLOYMENT_INVALID'],
]);

it('preserves disabled schema and reports pending and quarantined deliveries with reasons', function (): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $query = $f->pg->owner->prepare("INSERT INTO panel_a.driver_article_outbox (installation,panel,kind,host_type,host_key,id,incarnation,source_generation,event_id,digest,envelope,deliveries) VALUES ('host','acme.article','feed','article','42',?,1,'1',?,?,'{}',?::jsonb)");
        $query->execute(['019a1ba0-0000-7000-8000-000000000042', '019a1ba0-0000-7000-8000-000000000043', 'sha256:'.str_repeat('a', 64),
            json_encode(['sink.a' => ['status' => 'pending', 'reason' => 'retry'], 'sink.b' => ['status' => 'quarantine', 'reason' => 'consumer_disabled']], JSON_THROW_ON_ERROR)]);
        $result = new DoctorConsumer($f)->run(['selected' => false, 'enableModule' => false]);
        expect($result['exit'])->toBe(0, json_encode($result['data'], JSON_THROW_ON_ERROR))
            ->and(DoctorConsumer::field($result, 'data', 'components', 'acme.demo-targets', 'status'))->toBe('preserved_owned')
            ->and(DoctorConsumer::field($result, 'data', 'deliveries', 'sink.a:pending', 'count'))->toBe(1)
            ->and(DoctorConsumer::field($result, 'data', 'deliveries', 'sink.b:quarantine', 'reasons'))->toBe(['consumer_disabled'])
            ->and(PgTarget::query($f->pg->owner, 'SELECT count(*) FROM panel_a.driver_article_outbox')->fetchColumn())->toBe(1);
    } finally {
        $f->cleanup();
    }
});

it('rejects two configured aliases claiming the same physical panel tables', function (): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $result = new DoctorConsumer($f)->run(['alias' => true]);
        expect($result['exit'])->toBe(1)->and(DoctorConsumer::field($result, 'data', 'errors'))->toContain('STORAGE_OWNERSHIP_COLLISION');
    } finally {
        $f->cleanup();
    }
});

it('checks additive foreign keys and refuses changed references and incomplete applied ancestry', function (string $damage): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $f->boot(selected: true, contribution: UpgradeVariant::class, variant: 'fk');
        $f->repairModels();
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        // Disabled/unregistered module retains its complete frozen FK schema and concrete models.
        $options = ['selected' => false, 'enableModule' => false];
        $doctor = new DoctorConsumer($f);
        $ready = $doctor->run($options);
        expect($ready['exit'])->toBe(0, json_encode($ready['data'], JSON_THROW_ON_ERROR));
        $head = Scenario::object(json_decode((string) file_get_contents($f->manifests()[1]), true, flags: JSON_THROW_ON_ERROR));
        if ($damage === 'fk') {
            $entity = Scenario::object(Scenario::object(Scenario::object($head['physical'])['entities'])['demo_targets']);
            $key = Scenario::text(Scenario::object(Scenario::object($entity['foreign_keys'])['parent'])['name']);
            $f->pg->owner->exec('ALTER TABLE panel_a.driver_article_demo_targets DROP CONSTRAINT '.$key);
            $f->pg->owner->exec('ALTER TABLE panel_a.driver_article_demo_targets ADD CONSTRAINT '.$key.' FOREIGN KEY(parent_id) REFERENCES panel_a.driver_article_demo_targets(id) ON DELETE CASCADE');
            $code = 'SCHEMA_CONSTRAINT_INCOMPATIBLE';
        } else {
            $first = Scenario::object(json_decode((string) file_get_contents($f->manifests()[0]), true, flags: JSON_THROW_ON_ERROR));
            $query = $f->pg->owner->prepare('DELETE FROM public.driver_migrations WHERE migration=?');
            $query->execute([Scenario::text($first['migration'])]);
            $code = 'MIGRATION_LEDGER_NOT_PREFIX';
        }
        $result = $doctor->run($options);
        expect($result['exit'])->toBe(1)->and(DoctorConsumer::field($result, 'data', 'errors'))->toContain($code);
    } finally {
        $f->cleanup();
    }
})->with(['fk', 'ancestry']);

it('runs resolver constructor probes inside the same read-only boundary', function (string $sql): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $result = new DoctorConsumer($f)->run(['resolverSql' => $sql]);
        expect($result['exit'])->toBe($sql === 'write' ? 1 : 0);
        if ($sql === 'write') {
            expect(DoctorConsumer::field($result, 'data', 'errors'))->toContain('resolver.unavailable.tenant');
        } else {
            expect($result['sql'])->toContain('SELECT count(*) FROM public.p36_host');
        }
    } finally {
        $f->cleanup();
    }
})->with(['read', 'write']);
