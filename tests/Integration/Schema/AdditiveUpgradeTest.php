<?php

declare(strict_types=1);

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
        $old = $f->tree();
        $f->boot(selected: true, contribution: UpgradeVariant::class, variant: $variant);
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
