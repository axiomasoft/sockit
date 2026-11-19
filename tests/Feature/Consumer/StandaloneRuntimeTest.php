<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SocKit\Tests\Fixtures\ConsumerPackages;
use SocKit\Tests\Fixtures\Runtime\EnvelopeScenario;
use SocKit\Tests\Integration\Support\PgTarget;
use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $env
 */
function runConsumer(string $consumer, array $command, array $env = []): string
{
    $process = new Process($command, cwd: $consumer, env: ['APP_ENV' => 'testing', 'COMPOSER_NO_INTERACTION' => '1', ...$env]);
    $process->setTimeout(300);
    $process->mustRun();
    fwrite(STDERR, $process->getErrorOutput());

    return $process->getOutput();
}

it('installs and boots the standalone consumer with an owned PostgreSQL target', function (): void {
    // Fail closed before any fixture DB preparation, including when the parent uses phpunit.xml.
    $target = PgTarget::fromEnvironment();
    $target->connect();
    $target->connect(decoy: true);
    $root = sys_get_temp_dir().'/sockit-consumer-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $project = dirname(__DIR__, 3);
    $consumer = $root.'/tests/Fixtures/Consumer';
    try {
        $files->ensureDirectoryExists($root.'/packages');
        $files->ensureDirectoryExists($root.'/tests/Fixtures');
        ConsumerPackages::copy($files, $project, $root, social: false);
        $files->copyDirectory($project.'/tests/Fixtures/Consumer', $consumer);
        $files->ensureDirectoryExists($consumer.'/bootstrap/cache');
        runConsumer($consumer, ['composer', 'install', '--no-dev', '--no-interaction', '--no-scripts']);
        expect(is_link($consumer.'/vendor/axiomasoft/sockit'))->toBeFalse();
        $installedGraph = json_decode((string) file_get_contents($consumer.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($installedGraph) || ! isset($installedGraph['packages']) || ! is_array($installedGraph['packages'])) {
            throw new RuntimeException('Invalid installed graph');
        }
        $names = array_column($installedGraph['packages'], 'name');
        foreach (['axiomasoft/sockit-monorepo', 'axiomasoft/sockit-groups', 'axiomasoft/sockit-posts', 'axiomasoft/sockit-reactions', 'axiomasoft/sockit-testkit', 'axiomasoft/chatom', 'axiomasoft/forum', 'axiomasoft/azguard', 'axiomasoft/vaulter', 'axiomasoft/edufy', 'axiomasoft/corex', 'orchestra/testbench'] as $forbidden) {
            expect($names)->not->toContain($forbidden);
        }
        $source = $root.'/packages/core';
        $installed = $consumer.'/vendor/axiomasoft/sockit';
        $fingerprints = [];
        $filesInSource = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($filesInSource as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $relative = substr($file->getPathname(), strlen($source) + 1);
                $fingerprints[$relative] = hash_file('sha256', $file->getPathname());
                expect(is_file($installed.'/'.$relative))->toBeTrue()->and(hash_file('sha256', $installed.'/'.$relative))->toBe($fingerprints[$relative]);
            }
        }
        ksort($fingerprints);
        fwrite(STDERR, 'Installed package source fingerprint='.hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR)).PHP_EOL);
        runConsumer($consumer, [PHP_BINARY, 'artisan', 'package:discover']);
        runConsumer($consumer, [PHP_BINARY, 'artisan', 'config:cache'], ['SOCKIT_EMPTY' => '1']);
        $empty = json_decode(runConsumer($consumer, [PHP_BINARY, 'snapshot.php']), true, flags: JSON_THROW_ON_ERROR);
        expect($empty)->toBe(['discovered' => 1, 'panels' => [], 'connections' => 0, 'queries' => 0]);
        runConsumer($consumer, [PHP_BINARY, 'artisan', 'config:clear']);
        $before = json_decode(runConsumer($consumer, [PHP_BINARY, 'snapshot.php']), true, flags: JSON_THROW_ON_ERROR);
        runConsumer($consumer, [PHP_BINARY, 'artisan', 'config:cache']);
        $after = json_decode(runConsumer($consumer, [PHP_BINARY, 'snapshot.php']), true, flags: JSON_THROW_ON_ERROR);
        expect($after)->toBe($before)->and($after)->toBe(['discovered' => 1, 'panels' => ['acme.a', 'acme.b'], 'connections' => 0, 'queries' => 0]);
        $fixture = new EnvelopeScenario;
        try {
            runConsumer($consumer, [PHP_BINARY, 'artisan', 'config:clear']);
            runConsumer($consumer, [PHP_BINARY, 'artisan', 'config:cache'], ['SOCKIT_CONSUMER_NO_TARGET' => '1']);
            $negative = json_decode(runConsumer($consumer, [PHP_BINARY, 'negative.php']), true, flags: JSON_THROW_ON_ERROR);
            expect(is_array($negative))->toBeTrue();
            if (! is_array($negative)) {
                throw new RuntimeException('Invalid negative result');
            }
            expect($negative['assertion'] ?? null)->toBe('missing_target_denied');
            runConsumer($consumer, [PHP_BINARY, 'artisan', 'config:clear']);
            runConsumer($consumer, [PHP_BINARY, 'artisan', 'config:cache']);
            $pdo = $fixture->base->db->owner;
            foreach (['authority', 'targets', 'state', 'revisions'] as $table) {
                $pdo->exec('CREATE TABLE panel_b.other_envelope_'.$table.' (LIKE panel_a.driver_envelope_'.$table.' INCLUDING ALL)');
                $pdo->exec('INSERT INTO panel_b.other_envelope_'.$table.' SELECT * FROM panel_a.driver_envelope_'.$table);
            }
            $pdo->exec('DELETE FROM panel_b.other_envelope_revisions');
            $pdo->exec('DELETE FROM panel_b.other_b_surface_instances');
            $fixture->base->db->owner->exec('DELETE FROM panel_a.driver_envelope_revisions');
            $fixture->base->db->owner->exec('DELETE FROM panel_a.driver_a_surface_instances');
            $output = runConsumer($consumer, [PHP_BINARY, 'runtime.php']);
            fwrite(STDERR, $output.PHP_EOL);
            $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($result)) {
                throw new RuntimeException('Invalid consumer result');
            }
            expect($result['assertions'] ?? null)->toBe(['create', 'read', 'create_b', 'mutation_b', 'mutation', 'lookup', 'replay', 'payload_conflict', 'cas', 'rollback', 'relay', 'inbox', 'relay_repeat', 'revoked_replay', 'archive', 'resume', 'delete']);
        } finally {
            $fixture->cleanup();
        }
    } finally {
        $files->deleteDirectory($root);
    }
});
