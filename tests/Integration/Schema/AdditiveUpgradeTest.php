<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SocKit\Schema\Internal\MigrationHistory;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Tests\Fixtures\Schema\MigrationFixture;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;
use SocKit\Tests\Fixtures\Schema\UpgradeVariant;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('migrates initial and additive snapshots through Laravel and reverses only the new owned delta', function (): void {
    $f = new MigrationFixture;
    try {
        expect($f->command(panel: 'acme.product')['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $pdo = $f->pg->owner;
        $pdo->exec("INSERT INTO panel_b.driver_product_tombstones (installation,panel,kind,host_type,host_key,incarnation,surface_id,max_revision,source_generation,deleted_at) VALUES ('host','acme.product','article','article','42',1,'019a1ba0-0000-7000-8000-000000000042',1,1,now())");
        $product = PgTarget::query($pdo, 'SELECT row_to_json(t)::text FROM panel_b.driver_product_tombstones t')->fetchAll();
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $old = $f->tree();
        $f->boot(selected: true);
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        foreach ($old as $path => $hash) {
            expect($f->tree()[$path])->toBe($hash);
        }
        $pdo->exec("INSERT INTO panel_a.driver_article_demo_targets (id,installation,panel,external_key) VALUES ('019a1ba0-0000-7000-8000-000000000042','host','acme.article','article-data')");
        $f->boot(selected: true, version: 2);
        expect($f->command(upgrade: true)['data']['status'])->toBe('manual_changes_required');
        $f->repairModels();
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        expect(PgTarget::query($pdo, 'SELECT label FROM panel_a.driver_article_demo_targets')->fetchColumn())->toBeNull();
        $pdo->exec("UPDATE panel_a.driver_article_demo_targets SET label='kept-until-rollback'");
        $manifests = $f->manifests();
        expect($manifests)->toHaveCount(3);
        $before = $f->tree();
        expect($f->command(upgrade: true)['data']['status'])->toBe('no_op')->and($f->tree())->toBe($before);
        $initial = Scenario::object(json_decode((string) file_get_contents($manifests[0]), true, flags: JSON_THROW_ON_ERROR));
        expect(fn () => SnapshotFixture::load($f->root.'/database/migrations/'.Scenario::text($initial['migration']).'.php')->down())->toThrow(RuntimeException::class, 'SNAPSHOT_OWNERSHIP_CHANGED');
        expect($f->migrate(rollback: true)['exit'])->toBe(0);
        expect(PgTarget::query($pdo, "SELECT count(*) FROM information_schema.columns WHERE table_schema='panel_a' AND table_name='driver_article_demo_targets' AND column_name='label'")->fetchColumn())->toBe(0)
            ->and(PgTarget::query($pdo, 'SELECT external_key FROM panel_a.driver_article_demo_targets')->fetchColumn())->toBe('article-data');
        expect($f->migrate(rollback: true)['exit'])->toBe(0)
            ->and(PgTarget::query($pdo, "SELECT to_regclass('panel_a.driver_article_demo_targets')")->fetchColumn())->toBeNull();
        expect($f->migrate(rollback: true)['exit'])->toBe(0)
            ->and(PgTarget::query($pdo, 'SELECT row_to_json(t)::text FROM panel_b.driver_product_tombstones t')->fetchAll())->toBe($product)
            ->and(PgTarget::query($pdo, 'SELECT sentinel FROM public.p36_host')->fetchColumn())->toBe('host');
        fwrite(STDERR, "P3.6 SQL initial/module/column upgrades and reverse deltas passed; Product and host preserved; ancestor rollback refused\n");
    } finally {
        $f->cleanup();
    }
});

it('proves data constraints before generating a new index or check', function (string $variant): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $f->pg->owner->exec("INSERT INTO panel_a.driver_article_demo_targets (id,installation,panel,external_key) VALUES ('019a1ba0-0000-7000-8000-000000000041','host','acme.article','duplicate'),('019a1ba0-0000-7000-8000-000000000042','other','acme.article','duplicate')");
        $f->boot(selected: true, contribution: UpgradeVariant::class, variant: $variant);
        $before = $f->tree();
        $bad = $f->command(upgrade: true);
        expect($bad['exit'])->toBe(1)->and($bad['data']['problem'])->toBe('MIGRATION_DATA_CONSTRAINT_FAILED')->and($f->tree())->toBe($before);
        if ($variant === 'unique') {
            $f->pg->owner->exec('UPDATE panel_a.driver_article_demo_targets SET external_key=id::text');
        } else {
            $f->pg->owner->exec("UPDATE panel_a.driver_article_demo_targets SET external_key='allowed'");
        }
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        expect(PgTarget::query($f->pg->owner, 'SELECT count(*) FROM panel_a.driver_article_demo_targets')->fetchColumn())->toBe(2);
        expect($f->migrate(rollback: true)['exit'])->toBe(0);
        fwrite(STDERR, 'P3.6 SQL '.$variant." invalid data refused before file writes; repaired data upgraded and delta rolled back\n");
    } finally {
        $f->cleanup();
    }
})->with(['unique', 'check']);

it('adds defaulted columns and a nullable foreign key without changing prior rows or files', function (string $variant): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $f->pg->owner->exec("INSERT INTO panel_a.driver_article_demo_targets (id,installation,panel,external_key) VALUES ('019a1ba0-0000-7000-8000-000000000042','host','acme.article','original')");
        $f->boot(selected: true, contribution: UpgradeVariant::class, variant: $variant);
        $beforeRepair = $f->tree();
        expect($f->command(upgrade: true)['data']['status'])->toBe('manual_changes_required')->and($f->tree())->toBe($beforeRepair);
        $f->repairModels();
        $old = $f->tree();
        $result = $f->command(upgrade: true);
        expect($result['exit'])->toBe(0, json_encode($result, JSON_THROW_ON_ERROR))->and($f->migrate()['exit'])->toBe(0);
        $column = $variant === 'default' ? 'label' : 'parent_id';
        expect(PgTarget::query($f->pg->owner, 'SELECT '.$column.' FROM panel_a.driver_article_demo_targets')->fetchColumn())->toBe($variant === 'default' ? 'default-label' : null);
        foreach ($old as $path => $digest) {
            expect($f->tree()[$path])->toBe($digest);
        }
        expect($f->migrate(rollback: true)['exit'])->toBe(0)->and(PgTarget::query($f->pg->owner, 'SELECT external_key FROM panel_a.driver_article_demo_targets')->fetchColumn())->toBe('original');
        expect($f->migrate(rollback: true)['exit'])->toBe(0);
        fwrite(STDERR, 'P3.6 SQL '.$variant." additive constraint up/down and ancestor down passed\n");
    } finally {
        $f->cleanup();
    }
})->with(['default', 'fk', 'new_fk']);

it('rejects an ambiguous applied ledger and live ownership drift', function (string $damage): void {
    $f = new MigrationFixture;
    try {
        expect($f->command()['exit'])->toBe(0);
        $f->boot(selected: true);
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $records = array_map(fn (string $path): array => Scenario::object(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)), $f->manifests());
        $first = Scenario::text($records[0]['migration']);
        if ($damage === 'ledger_gap') {
            $statement = $f->pg->owner->prepare('DELETE FROM public.driver_migrations WHERE migration=?');
            $statement->execute([$first]);
        } elseif ($damage === 'ledger_unknown') {
            $statement = $f->pg->owner->prepare('INSERT INTO public.driver_migrations (migration,batch) VALUES (?,3)');
            $statement->execute([substr($first, 0, 18).'999999_sockit_'.substr(hash('sha256', 'acme.article'), 0, 16)]);
        } else {
            $f->pg->owner->exec("COMMENT ON TABLE panel_a.driver_article_outbox IS 'foreign receipt'");
        }
        $before = $f->tree();
        $bad = $f->command(upgrade: true);
        expect($bad['exit'])->toBe(1)->and($bad['data']['problem'])->toBe($damage === 'catalog' ? 'MIGRATION_APPLIED_OWNERSHIP_CHANGED' : 'MIGRATION_LEDGER_NOT_PREFIX')
            ->and($f->tree())->toBe($before);
        fwrite(STDERR, 'P3.6 SQL '.$damage." refused without filesystem mutation\n");
    } finally {
        $f->cleanup();
    }
})->with(['ledger_gap', 'ledger_unknown', 'catalog']);

it('atomically reverses a failing additive program including its ownership receipts', function (): void {
    $f = new MigrationFixture(selected: true);
    $path = null;
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $f->boot(selected: true, version: 2);
        $f->repairModels();
        $plan = $f->plan(upgrade: true);
        $source = $plan->sources[Scenario::text($plan->manifest['migration'])];
        $snapshot = MigrationHistory::snapshot($source);
        $program = Scenario::object($snapshot['program']);
        $up = Scenario::entries($program['up']);
        $program['up'] = [...$up, 'SELECT 1/0'];
        $json = SchemaValues::json([...$snapshot, 'program' => $program]);
        if (preg_match("/public const string SNAPSHOT = '([^']+)';/", $source, $encoded) !== 1
            || preg_match("/public const string DIGEST = '([^']+)';/", $source, $digest) !== 1) {
            throw new RuntimeException('Missing frozen snapshot constants');
        }
        $path = SnapshotFixture::file(strtr($source, [$encoded[1] => base64_encode($json), $digest[1] => hash('sha256', $json)]));
        $before = PgTarget::query($f->pg->owner, "SELECT obj_description('panel_a.driver_article_demo_targets'::regclass)")->fetchColumn();
        expect(fn () => SnapshotFixture::load($path)->up())->toThrow(RuntimeException::class, 'SNAPSHOT_DDL_FAILED')
            ->and(PgTarget::query($f->pg->owner, "SELECT obj_description('panel_a.driver_article_demo_targets'::regclass)")->fetchColumn())->toBe($before)
            ->and(PgTarget::query($f->pg->owner, "SELECT count(*) FROM information_schema.columns WHERE table_schema='panel_a' AND table_name='driver_article_demo_targets' AND column_name='label'")->fetchColumn())->toBe(0);
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        fwrite(STDERR, "P3.6 SQL injected middle DDL failure leaves no partial column/index or changed ownership; retry passed\n");
    } finally {
        if ($path !== null) {
            unlink($path);
        }
        $f->cleanup();
    }
});

it('preserves custom code while requiring JSON casts and both new FK relations before generation', function (): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $models = array_values(array_filter(array_keys($f->tree()), fn (string $path): bool => str_ends_with($path, '/DemoTarget.php')));
        $path = $f->root.'/'.$models[0];
        file_put_contents($path, "\n// custom JSON/FK sentinel\n", FILE_APPEND);
        $f->boot(selected: true, contribution: UpgradeVariant::class, variant: 'json_fk');
        $before = $f->tree();
        $result = $f->command(upgrade: true);
        $changes = Scenario::object(Scenario::object($result['data']['model_changes'])['demo_targets']);
        expect($result['exit'])->toBe(1)->and($result['data']['status'])->toBe('manual_changes_required')
            ->and(Scenario::object($changes['required_casts'])['metadata'])->toBe('array')
            ->and(Scenario::object($changes['required_methods']))->toHaveKeys(['parent', 'demotargetsparent'])
            ->and($f->tree())->toBe($before)->and($f->manifests())->toHaveCount(1);
        $f->repairModels(relations: false);
        $bindingOnly = $f->tree();
        $still = $f->command(upgrade: true);
        expect($still['exit'])->toBe(1)->and($still['data']['status'])->toBe('manual_changes_required')
            ->and(Scenario::object(Scenario::object($still['data']['model_changes'])['demo_targets'])['binding_valid'])->toBeTrue()
            ->and($f->tree())->toBe($bindingOnly);
        $f->repairModels();
        $repaired = $f->tree();
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        foreach ($repaired as $file => $digest) {
            expect($f->tree()[$file])->toBe($digest);
        }
        expect(file_get_contents($path))->toContain('custom JSON/FK sentinel');
        $probe = $f->child(['operation' => 'validate', 'crud' => true]);
        expect($probe['models_valid'])->toBeTrue()->and($probe['metadata'])->toBe(['nested' => ['ok' => true]])
            ->and($probe['relations'])->toBe([BelongsTo::class, HasMany::class]);
        $validSource = (string) file_get_contents($path);
        file_put_contents($path, str_replace('parent(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo', 'parent(): bool', $validSource));
        $badSignature = $f->tree();
        $signatureResult = $f->command(upgrade: true);
        expect($signatureResult['exit'])->toBe(1)->and($signatureResult['data']['status'])->toBe('manual_changes_required')
            ->and(Scenario::object(Scenario::object(Scenario::object($signatureResult['data']['model_changes'])['demo_targets'])['required_methods']))->toHaveKey('parent')
            ->and($f->tree())->toBe($badSignature);
        file_put_contents($path, $validSource);
        expect($f->command(upgrade: true)['data']['status'])->toBe('no_op');
        fwrite(STDERR, "P3.6 F1 SQL manual JSON casts and both FK methods required; consumer code retained; ModelBindings validates; JSON array roundtrip and real Eloquent relations passed\n");
    } finally {
        $f->cleanup();
    }
});

it('upgrades a real Acme make-panel kernel consumer with missing module and entity models', function (string $directory): void {
    $f = new MigrationFixture(namespace: 'Acme\\', directory: $directory);
    try {
        $generated = $f->makePanel();
        expect($generated['exit'])->toBe(0);
        $provider = Scenario::text($generated['data']['provider']);
        $f->generated($provider);
        expect($f->command()['exit'])->toBe(0)->and($f->migrate()['exit'])->toBe(0);
        $file = $f->root.'/'.$directory.'/Panels/ArticlePanelProvider.php';
        file_put_contents($file, str_replace('->modules()', "->modules('acme.demo-targets')", (string) file_get_contents($file)));
        $providerHash = hash_file('sha256', $file);
        $enable = $f->command(upgrade: true);
        expect($enable['exit'])->toBe(0, json_encode($enable, JSON_THROW_ON_ERROR));
        $class = Scenario::text(Scenario::object($enable['data']['models'])['demo_targets']);
        expect($class)->toStartWith('Acme\\')->and(class_exists($class))->toBeTrue()
            ->and($f->migrate()['exit'])->toBe(0)->and($f->child(['operation' => 'validate'])['models_valid'])->toBeTrue();
        $f->boot(selected: true, contribution: UpgradeVariant::class, variant: 'new_fk');
        $f->generated($provider);
        expect($f->command(upgrade: true)['data']['status'])->toBe('manual_changes_required');
        $f->repairModels();
        $entity = $f->command(upgrade: true);
        expect($entity['exit'])->toBe(0, json_encode($entity, JSON_THROW_ON_ERROR));
        $newClass = Scenario::text(Scenario::object($entity['data']['models'])['demo_notes']);
        expect($newClass)->toStartWith('Acme\\')->and(class_exists($newClass))->toBeTrue()
            ->and($f->migrate()['exit'])->toBe(0)->and($f->child(['operation' => 'validate'])['models_valid'])->toBeTrue()
            ->and($f->command(upgrade: true)['data']['status'])->toBe('no_op')
            ->and(hash_file('sha256', $file))->toBe($providerHash);
        fwrite(STDERR, "P3.6 F3 SQL actual Acme make-panel kernel consumer enabled module and appended entity; missing models generated in consumer namespace and ModelBindings passed; provider preserved\n");
    } finally {
        $f->cleanup();
    }
})->with(['app', 'src']);
