<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

/** @param list<string> $command */
function runPanelConsumer(string $consumer, array $command, int $expected = 0): string
{
    $process = new Process($command, cwd: $consumer, env: ['APP_ENV' => 'testing', 'COMPOSER_NO_INTERACTION' => '1']);
    $process->setTimeout(180);
    $exit = $process->run();
    fwrite(STDERR, 'consumer command='.json_encode($command, JSON_THROW_ON_ERROR).' exit='.$exit.PHP_EOL.$process->getErrorOutput());
    expect($exit)->toBe($expected, $process->getOutput());

    return $process->getOutput();
}

it('composer-installs the CLI and loads only generated provider/models across fresh config-cache boots without DDL', function (): void {
    $target = PgTarget::fromEnvironment();
    // Prove container, loopback endpoint, run marker and exact *_test databases before mutations.
    $owner = $target->connect();
    $decoy = $target->connect(decoy: true);
    $catalog = static fn (PDO $pdo): array => PgTarget::query($pdo, "SELECT n.nspname, c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT LIKE 'pg_%' AND n.nspname <> 'information_schema' ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC);
    $ownerBefore = $catalog($owner);
    $decoyBefore = $catalog($decoy);
    $root = sys_get_temp_dir().'/sockit-panel-consumer-'.bin2hex(random_bytes(16));
    $files = new Filesystem;
    $project = dirname(__DIR__, 3);
    $consumer = $root.'/tests/Fixtures/PanelConsumer';
    try {
        $files->ensureDirectoryExists($root.'/packages');
        $files->ensureDirectoryExists($root.'/tests/Fixtures');
        $files->copyDirectory($project.'/packages/core', $root.'/packages/core');
        $files->copyDirectory($project.'/tests/Fixtures/PanelConsumer', $consumer);
        $files->ensureDirectoryExists($consumer.'/bootstrap/cache');
        $files->ensureDirectoryExists($consumer.'/storage/framework/views');
        runPanelConsumer($consumer, ['composer', 'install', '--no-dev', '--no-interaction', '--no-scripts']);
        expect(is_link($consumer.'/vendor/axiomasoft/sockit'))->toBeFalse();
        $fingerprints = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages/core', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $relative = substr($file->getPathname(), strlen($root.'/packages/core/'));
                $fingerprints[$relative] = hash_file('sha256', $file->getPathname());
                expect(hash_file('sha256', $consumer.'/vendor/axiomasoft/sockit/'.$relative))->toBe($fingerprints[$relative]);
            }
        }
        ksort($fingerprints);
        fwrite(STDERR, 'Installed package source fingerprint='.hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR)).PHP_EOL);
        runPanelConsumer($consumer, [PHP_BINARY, 'artisan', 'package:discover']);
        $list = runPanelConsumer($consumer, [PHP_BINARY, 'artisan', 'list', '--raw']);
        expect($list)->toContain('sockit:make-panel');
        $command = [PHP_BINARY, 'artisan', 'sockit:make-panel', 'Article', '--id=acme.article', '--host=article', '--modules=acme.demo-targets', '--prefix=article_', '--connection=owner_a'];
        $dry = Scenario::object(json_decode(runPanelConsumer($consumer, [...$command, '--dry-run']), true, flags: JSON_THROW_ON_ERROR));
        expect($dry['status'])->toBe('dry_run')->and(is_dir($consumer.'/app/Panels'))->toBeFalse()->and(is_dir($consumer.'/app/Models'))->toBeFalse();
        $manifest = Scenario::object(json_decode(runPanelConsumer($consumer, $command), true, flags: JSON_THROW_ON_ERROR));
        expect($manifest['status'])->toBe('installed')->and($manifest['files'])->toHaveCount(6)
            ->and($manifest['registration'])->toContain("'panels' => [\\Acme\\Panels\\ArticlePanelProvider::class]");
        $beforeRepeat = [];
        foreach (Scenario::object($manifest['files']) as $path => $class) {
            $beforeRepeat[$path] = hash_file('sha256', $consumer.'/'.$path);
        }
        runPanelConsumer($consumer, $command, expected: 1);
        foreach ($beforeRepeat as $path => $hash) {
            expect(hash_file('sha256', $consumer.'/'.$path))->toBe($hash);
        }
        // Apply the emitted registration instruction to this disposable host; no handwritten panel.
        $config = (string) file_get_contents($consumer.'/config/sockit.php');
        file_put_contents($consumer.'/config/sockit.php', str_replace("'panels' => []", "'panels' => [\\Acme\\Panels\\ArticlePanelProvider::class]", $config));
        $before = Scenario::object(json_decode(runPanelConsumer($consumer, [PHP_BINARY, 'snapshot.php']), true, flags: JSON_THROW_ON_ERROR));
        runPanelConsumer($consumer, [PHP_BINARY, 'artisan', 'config:cache']);
        $after = Scenario::object(json_decode(runPanelConsumer($consumer, [PHP_BINARY, 'snapshot.php']), true, flags: JSON_THROW_ON_ERROR));
        expect($after)->toBe($before)->and($after['panels'])->toBe(['acme.article'])->and($after['bindings'])->toHaveCount(5)
            ->and(Scenario::object($after['bindings'])['demo_targets'])->toBe(['public.article_demo_targets', 'owner_a', 'acme.article'])
            ->and($after['default'])->toBe('decoy')->and($after['connections'])->toBe(0)->and($after['queries'])->toBe(0);
        expect($catalog($owner))->toBe($ownerBefore)->and($catalog($decoy))->toBe($decoyBefore);
        fwrite(STDERR, 'SQL assertions: owner catalog unchanged; decoy catalog unchanged; boot/config-cache queries=0; generated ModelBindings validated'.PHP_EOL);
        fwrite(STDERR, json_encode($after, JSON_THROW_ON_ERROR).PHP_EOL);
    } finally {
        $files->deleteDirectory($root);
    }
});
