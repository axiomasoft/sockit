<?php

declare(strict_types=1);

use SocKit\Internal\Generation\ExclusiveWriter;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Tests\Fixtures\Schema\MigrationFixture;
use SocKit\Tests\Fixtures\Schema\UpgradeVariant;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

// The literal V1 command has no ambient DB. Its child owns the entire PG lifetime.
if (getenv('SOCKIT_PG_MANIFEST') === false) {
    it('runs the migration CLI scenarios on an owned isolated PostgreSQL target', function (): void {
        $process = new Process(['python3', 'scripts/testing/pg-run.py', 'composer', 'test', '--', '--configuration', 'phpunit.pg.xml', __FILE__,
            '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-warning', '--fail-on-risky'], dirname(__DIR__, 3));
        $process->setTimeout(180);
        $process->run();
        fwrite(STDERR, $process->getOutput().$process->getErrorOutput());
        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    });

    return;
}

it('creates initial immutable files and missing models then reports a zero-output no-op', function (): void {
    $fixture = new MigrationFixture;
    try {
        $before = $fixture->tree();
        $dry = $fixture->command(dryRun: true);
        expect($dry['exit'])->toBe(0)->and($dry['data']['status'])->toBe('dry_run')->and($fixture->tree())->toBe($before);
        expect($fixture->command()['exit'])->toBe(0);
        $installed = $fixture->tree();
        expect($fixture->command()['exit'])->toBe(1)->and($fixture->tree())->toBe($installed);
        expect($fixture->command(upgrade: true)['data']['status'])->toBe('no_op')->and($fixture->tree())->toBe($installed);
    } finally {
        $fixture->cleanup();
    }
});

it('plans an entire upgrade without writes and preserves custom provider/model and ancestor hashes', function (): void {
    $f = new MigrationFixture;
    try {
        expect($f->command()['exit'])->toBe(0);
        $model = array_values(array_filter(array_keys($f->tree()), fn (string $path): bool => str_ends_with($path, '/Receipt.php')))[0];
        file_put_contents($f->root.'/'.$model, "\n// consumer customization\n", FILE_APPEND);
        $old = $f->tree();
        $f->boot(selected: true);
        $dry = $f->command(upgrade: true, dryRun: true);
        expect($dry['exit'])->toBe(0)->and($dry['data']['status'])->toBe('dry_run')->and($dry['data']['files'])->toHaveCount(3)
            ->and($dry['data']['models'])->toHaveCount(5)->and($dry['data']['tables'])->toHaveCount(5)->and($f->tree())->toBe($old);
        expect($f->command(upgrade: true)['exit'])->toBe(0);
        foreach ($old as $path => $digest) {
            expect($f->tree()[$path])->toBe($digest);
        }
        expect($f->command(upgrade: true)['data']['status'])->toBe('no_op');
    } finally {
        $f->cleanup();
    }
});

it('rejects missing or tampered ancestry before any writes', function (string $damage): void {
    $f = new MigrationFixture;
    try {
        expect($f->command()['exit'])->toBe(0);
        $manifest = $f->manifests()[0];
        $record = Scenario::object(json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR));
        $migration = $f->root.'/database/migrations/'.Scenario::text($record['migration']).'.php';
        match ($damage) {
            'source' => file_put_contents($migration, "\n// tamper\n", FILE_APPEND),
            'manifest' => file_put_contents($manifest, str_replace('uuid7', 'uuid8', (string) file_get_contents($manifest))),
            'gap' => rename($manifest, dirname($manifest).'/000002.json'),
            'branch' => copy($manifest, dirname($manifest).'/branch.json'),
            'missing_migration' => unlink($migration),
            'missing_manifest' => unlink($manifest),
            default => throw new RuntimeException('Unknown damage'),
        };
        $before = $f->tree();
        $f->boot(selected: true);
        expect($f->command(upgrade: true)['exit'])->toBe(1)->and($f->tree())->toBe($before);
    } finally {
        $f->cleanup();
    }
})->with(['source', 'manifest', 'gap', 'branch', 'missing_migration', 'missing_manifest']);

it('rejects rewritten steps required columns and physical rebinding without touching output', function (string $change): void {
    $f = new MigrationFixture(selected: true);
    try {
        expect($f->command()['exit'])->toBe(0);
        if ($change === 'rebind') {
            $f->boot(selected: true, prefix: 'renamed_');
        } else {
            $f->boot(selected: true, contribution: UpgradeVariant::class, variant: $change);
        }
        $before = $f->tree();
        $result = $f->command(upgrade: true);
        expect($result['exit'])->toBe(1)->and($result['data']['problem'])->toBe(match ($change) {
            'rebind' => 'MIGRATION_BINDING_CHANGED', 'rewrite' => 'MIGRATION_STEPS_REWRITTEN', 'required' => 'UPGRADE_REQUIRED_COLUMN_UNSAFE',
            default => throw new RuntimeException('Unknown change'),
        })->and($f->tree())->toBe($before);
    } finally {
        $f->cleanup();
    }
})->with(['rewrite', 'required', 'rebind']);

it('cleans the unified model migration manifest write after injected failure and reports exact recovery paths', function (bool $foreignEdit): void {
    $f = new MigrationFixture;
    try {
        expect($f->command()['exit'])->toBe(0);
        $old = $f->tree();
        $f->boot(selected: true);
        $plan = $f->plan(upgrade: true);
        $installed = [];
        $writer = new ExclusiveWriter(static function (string $phase, string $path) use ($f, $foreignEdit, &$installed): void {
            if ($phase === 'installed') {
                $installed[] = $path;
                if (count($installed) === 2) {
                    if ($foreignEdit) {
                        file_put_contents($f->root.'/'.$installed[0], "\n// new consumer edit\n", FILE_APPEND);
                    }
                    throw new RuntimeException('Injected mid-install failure');
                }
            }
        });
        try {
            $writer->install($plan);
            throw new RuntimeException('Expected generation failure');
        } catch (GenerationFailure $error) {
            expect($error->getMessage())->toBe('GENERATION_WRITE_FAILED');
            if ($foreignEdit) {
                expect($error->recovery)->toContain($installed[0])->and(file_get_contents($f->root.'/'.$installed[0]))->toContain('new consumer edit');
            } else {
                expect($error->recovery)->toBe([])->and($f->tree())->toBe($old);
            }
        }
        foreach ($old as $path => $digest) {
            expect($f->tree()[$path])->toBe($digest);
        }
        expect($f->manifests())->toHaveCount(1);
        if (! $foreignEdit) {
            expect(glob($f->root.'/.sockit-stage-*'))->toBe([]);
        }
    } finally {
        $f->cleanup();
    }
})->with([false, true]);

it('repairs only a missing model without creating another snapshot and refuses unsafe paths', function (): void {
    $f = new MigrationFixture;
    try {
        expect($f->command()['exit'])->toBe(0);
        $models = array_values(array_filter(array_keys($f->tree()), fn (string $path): bool => str_ends_with($path, '/Receipt.php')));
        $model = $models[0];
        unlink($f->root.'/'.$model);
        $before = $f->tree();
        expect($f->command(upgrade: true, dryRun: true)['data']['files'])->toBe([$model])->and($f->tree())->toBe($before);
        expect($f->command(upgrade: true)['exit'])->toBe(0)->and($f->manifests())->toHaveCount(1);
        unlink($f->root.'/'.$model);
        symlink($f->root.'/composer.json', $f->root.'/'.$model);
        expect($f->command(upgrade: true)['exit'])->toBe(1);
    } finally {
        $f->cleanup();
    }
});
