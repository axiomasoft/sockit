<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use SocKit\Tests\Fixtures\ConsumerPackages;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

/** @param list<string> $command */
function runGeneratedConsumer(string $root, array $command, int $expected = 0): string
{
    $process = new Process($command, cwd: $root, env: ['APP_ENV' => 'testing', 'COMPOSER_NO_INTERACTION' => '1']);
    $process->setTimeout(300);
    $exit = $process->run();
    fwrite(STDERR, 'consumer command='.json_encode($command, JSON_THROW_ON_ERROR).' exit='.$exit.PHP_EOL.$process->getErrorOutput().$process->getOutput().PHP_EOL);
    expect($exit)->toBe($expected);

    return $process->getOutput();
}

it('installs generated panels in a fresh consumer with isolated production snapshots', function (): void {
    $target = PgTarget::fromEnvironment();
    $owner = $target->connect();
    $decoy = $target->connect(decoy: true);
    $ownedPattern = '/^(?:p38_host|driver_(?:article|product|envelope)_[a-z0-9_]+|driver_migrations)$/D';
    $tables = PgTarget::query($owner, "SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (! is_string($table) || preg_match($ownedPattern, $table) === 1) {
            throw new RuntimeException('P3 consumer table already exists before test');
        }
    }
    $root = sys_get_temp_dir().'/sockit-p38-'.bin2hex(random_bytes(16));
    $project = dirname(__DIR__, 3);
    $consumer = $root.'/tests/Fixtures/PanelConsumer';
    $files = new Filesystem;
    try {
        $files->ensureDirectoryExists($root.'/packages');
        $files->ensureDirectoryExists($root.'/tests/Fixtures');
        ConsumerPackages::copy($files, $project, $root, social: true);
        $files->copyDirectory($project.'/tests/Fixtures/PanelConsumer', $consumer);
        $files->ensureDirectoryExists($consumer.'/bootstrap/cache');
        $files->ensureDirectoryExists($consumer.'/storage/framework/views');
        $observed = PgTarget::query($owner, 'SELECT host(inet_server_addr()) AS host, inet_server_port() AS port')->fetch(PDO::FETCH_ASSOC);
        if (! is_array($observed)) {
            throw new RuntimeException('Missing verified server endpoint');
        }
        $fixtureTarget = $consumer.'/app/PgTarget.php';
        $source = (string) file_get_contents($fixtureTarget);
        file_put_contents($fixtureTarget, str_replace("'cluster' => 'test-'", "'aliases' => [".var_export($observed, true)."], 'cluster' => 'test-'", $source));
        runGeneratedConsumer($consumer, ['composer', 'install', '--no-dev', '--no-interaction', '--no-scripts']);
        $installed = $consumer.'/vendor/axiomasoft/sockit';
        expect(is_link($installed))->toBeFalse();
        $graph = Scenario::object(json_decode((string) file_get_contents($consumer.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR));
        $packages = $graph['packages'];
        if (! is_array($packages)) {
            throw new RuntimeException('Invalid installed package graph');
        }
        $names = array_column($packages, 'name');
        foreach (['axiomasoft/sockit-monorepo', 'axiomasoft/chatom', 'axiomasoft/forum', 'axiomasoft/azguard', 'axiomasoft/vaulter', 'axiomasoft/edufy', 'axiomasoft/corex', 'orchestra/testbench'] as $forbidden) {
            expect($names)->not->toContain($forbidden);
        }
        $fingerprints = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project.'/packages/core', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $relative = substr($file->getPathname(), strlen($project.'/packages/core/'));
                $fingerprints[$relative] = hash_file('sha256', $file->getPathname());
                expect(hash_file('sha256', $installed.'/'.$relative))->toBe($fingerprints[$relative]);
            }
        }
        ksort($fingerprints);
        fwrite(STDERR, 'Installed package source fingerprint='.hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR)).PHP_EOL);
        runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'package:discover']);
        foreach (['Article' => 'article', 'Product' => 'product'] as $name => $slug) {
            $command = [PHP_BINARY, 'artisan', 'sockit:make-panel', $name, '--id=acme.'.$slug, '--host='.$slug, '--modules=acme.demo-targets', '--prefix='.$slug.'_', '--connection=owner_'.($slug === 'article' ? 'a' : 'b')];
            $manifest = Scenario::object(json_decode(runGeneratedConsumer($consumer, $command), true, flags: JSON_THROW_ON_ERROR));
            expect($manifest['status'])->toBe('installed');
            $hashes = [];
            foreach (Scenario::object($manifest['files']) as $path => $class) {
                $hashes[$path] = hash_file('sha256', $consumer.'/'.$path);
            }
            runGeneratedConsumer($consumer, $command, expected: 1);
            foreach ($hashes as $path => $hash) {
                expect(hash_file('sha256', $consumer.'/'.$path))->toBe($hash);
            }
        }
        $configPath = $consumer.'/config/sockit.php';
        $config = (string) file_get_contents($configPath);
        file_put_contents($configPath, str_replace("'panels' => []", "'panels' => [\\Acme\\Panels\\ArticlePanelProvider::class, \\Acme\\Panels\\ProductPanelProvider::class]", $config));
        $config = (string) file_get_contents($configPath);
        $config = str_replace("'enabled' => ['acme.demo-targets']", "'enabled' => ['acme.demo-targets', 'acme.runtime', 'acme.demo-links']", $config);
        $config = str_replace("'storage' =>", "'surfaces' => [\\Acme\\Runtime\\ArticleSurface::class], 'resolvers' => ['actor' => \\Acme\\Runtime\\Actor::class, 'tenant' => \\Acme\\Runtime\\Tenant::class, 'contour' => \\Acme\\Runtime\\Host::class, 'groups' => \\Acme\\Runtime\\Host::class, 'surface_policy' => \\Acme\\Runtime\\Host::class, 'targets' => \\Acme\\Runtime\\TargetRouter::class, 'authorizer' => \\Acme\\Runtime\\AuthorityRouter::class, 'audience' => \\SocKit\\Audience\\AudienceEvaluator::class], 'storage' =>", $config);
        file_put_contents($configPath, $config);
        $providers = $consumer.'/bootstrap/providers.php';
        file_put_contents($providers, str_replace('FixtureProvider::class]', 'FixtureProvider::class, \\Acme\\Runtime\\RuntimeProvider::class]', (string) file_get_contents($providers)));
        $articleProvider = $consumer.'/app/Panels/ArticlePanelProvider.php';
        $providerSource = (string) file_get_contents($articleProvider);
        file_put_contents($articleProvider, str_replace("->modules('acme.demo-targets')", "->modules('acme.demo-targets', 'acme.runtime')", $providerSource)."\n// P3.8 consumer provider marker\n");
        $beforeBoot = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'snapshot.php']), true, flags: JSON_THROW_ON_ERROR));
        runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'config:cache']);
        $afterBoot = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'snapshot.php']), true, flags: JSON_THROW_ON_ERROR));
        expect($afterBoot)->toBe($beforeBoot)->and($afterBoot['panels'])->toBe(['acme.article', 'acme.product'])->and($afterBoot['connections'])->toBe(0)->and($afterBoot['queries'])->toBe(0)->and($afterBoot['default'])->toBe('decoy');
        $owner->exec('CREATE TABLE public.p38_host (sentinel text NOT NULL)');
        $owner->exec("INSERT INTO public.p38_host VALUES ('preserved')");
        $paths = [];
        foreach (['article', 'product'] as $panel) {
            $result = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.'.$panel]), true, flags: JSON_THROW_ON_ERROR));
            expect($result['status'])->toBe('generated');
            $paths[$panel] = Scenario::text($result['migration']);
            runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'migrate', '--database=owner_'.($panel === 'article' ? 'a' : 'b'), '--path='.$paths[$panel], '--force']);
        }
        foreach (['article', 'product'] as $panel) {
            foreach (['surface_instances', 'receipts', 'outbox', 'tombstones', 'demo_targets'] as $table) {
                expect(PgTarget::query($owner, "SELECT to_regclass('public.driver_{$panel}_{$table}')")->fetchColumn())->not->toBeNull();
            }
        }
        expect(PgTarget::query($decoy, "SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn())->toBe(0);
        $owner->exec('CREATE TABLE public.driver_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
        $owner->exec('INSERT INTO public.driver_envelope_authority (allowed) VALUES (true)');
        $owner->exec('CREATE TABLE public.driver_envelope_targets (id text PRIMARY KEY, incarnation bigint NOT NULL, generation text NOT NULL, privacy bigint NOT NULL, visible boolean NOT NULL, lifecycle text NOT NULL, held boolean NOT NULL)');
        $owner->exec("INSERT INTO public.driver_envelope_targets VALUES ('42',1,'1',1,true,'live',false)");
        $owner->exec('CREATE TABLE public.driver_envelope_state (target text PRIMARY KEY, effects bigint NOT NULL)');
        $owner->exec("INSERT INTO public.driver_envelope_state VALUES ('42',0)");
        $owner->exec('CREATE TABLE public.driver_envelope_revisions (target text PRIMARY KEY, resource text NOT NULL, revision bigint NOT NULL)');
        $runtime = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'runtime.php']), true, flags: JSON_THROW_ON_ERROR));
        expect($runtime['assertions'])->toBe(['create', 'current_admission', 'mutation', 'replay', 'atomic_rollback', 'revoked_replay']);
        expect(PgTarget::query($owner, "SELECT to_regclass('public.driver_article_demo_parents')")->fetchColumn())->toBeNull();
        $modelPath = $consumer.'/app/Models/SocKit/Article/DemoTarget.php';
        file_put_contents($modelPath, "\n// P3.8 consumer model marker\n", FILE_APPEND);
        $oldHashes = [$paths['article'] => hash_file('sha256', $consumer.'/'.$paths['article']), $modelPath => hash_file('sha256', $modelPath), $articleProvider => hash_file('sha256', $articleProvider)];
        $modelResult = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'models.php']), true, flags: JSON_THROW_ON_ERROR));
        $restored = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'models.php', 'restore', Scenario::text($modelResult['article'])]), true, flags: JSON_THROW_ON_ERROR));
        expect($restored)->toBe(['panel' => 'acme.article', 'connection' => 'owner_a']);
        $providerContent = (string) file_get_contents($articleProvider);
        file_put_contents($articleProvider, str_replace("->modules('acme.demo-targets', 'acme.runtime')", "->modules('acme.demo-targets', 'acme.runtime', 'acme.demo-links')", $providerContent));
        $oldHashes[$articleProvider] = hash_file('sha256', $articleProvider);
        $upgrade = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.article', '--upgrade']), true, flags: JSON_THROW_ON_ERROR));
        expect($upgrade['status'])->toBe('generated');
        $upgradePath = Scenario::text($upgrade['migration']);
        foreach ($oldHashes as $path => $hash) {
            $absolute = str_starts_with($path, $consumer.'/') ? $path : $consumer.'/'.$path;
            expect(hash_file('sha256', $absolute))->toBe($hash);
        }
        runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'migrate', '--database=owner_a', '--path='.$upgradePath, '--force']);
        $relations = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'relations.php', Scenario::text(Scenario::object($upgrade['models'])['demo_parents']), Scenario::text(Scenario::object($upgrade['models'])['demo_children'])]), true, flags: JSON_THROW_ON_ERROR));
        expect($relations['has_many'])->toBe(1)->and($relations['connection'])->toBe('owner_a');
        $noop = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.article', '--upgrade']), true, flags: JSON_THROW_ON_ERROR));
        expect($noop['status'])->toBe('no_op');
        $eventBefore = PgTarget::query($owner, "SELECT md5(string_agg(envelope, '|' ORDER BY id)) FROM public.driver_article_outbox")->fetchColumn();
        $providerContent = (string) file_get_contents($articleProvider);
        file_put_contents($articleProvider, str_replace("->modules('acme.demo-targets', 'acme.runtime', 'acme.demo-links')", "->modules('acme.demo-targets')", $providerContent));
        $config = (string) file_get_contents($configPath);
        file_put_contents($configPath, str_replace("'enabled' => ['acme.demo-targets', 'acme.runtime', 'acme.demo-links']", "'enabled' => ['acme.demo-targets']", $config));
        runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'config:clear']);
        runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'config:cache']);
        $disabled = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.article', '--upgrade']), true, flags: JSON_THROW_ON_ERROR));
        expect($disabled['status'])->toBe('no_op')
            ->and(PgTarget::query($owner, "SELECT to_regclass('public.driver_article_demo_parents')")->fetchColumn())->not->toBeNull()
            ->and(PgTarget::query($owner, "SELECT md5(string_agg(envelope, '|' ORDER BY id)) FROM public.driver_article_outbox")->fetchColumn())->toBe($eventBefore);
        $quarantine = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'runtime.php', 'quarantine']), true, flags: JSON_THROW_ON_ERROR));
        expect($quarantine['quarantine'])->toBe('outbox.consumer_unavailable')
            ->and(PgTarget::query($owner, "SELECT count(*) FROM public.driver_article_outbox WHERE deliveries::jsonb->'acme.inbox'->>'status' = 'quarantine'")->fetchColumn())->toBe(2)
            ->and(PgTarget::query($owner, "SELECT md5(string_agg(envelope, '|' ORDER BY id)) FROM public.driver_article_outbox")->fetchColumn())->toBe($eventBefore);
        $contribution = $consumer.'/app/Runtime/LinksContribution.php';
        $changed = str_replace("id: 'acme.demo-links', version: 2, steps:", "id: 'acme.demo-links', version: 3, steps:", (string) file_get_contents($contribution));
        $changed = str_replace('        ]);', "            new SchemaStep(id: 'add_later', version: 3, entity: 'demo_parents', columns: ['later' => new SchemaColumn(type: 'text', nullable: true)]),\n        ]);", $changed);
        file_put_contents($contribution, $changed);
        $productBefore = PgTarget::query($owner, "SELECT md5(string_agg(external_key || ':' || panel, ',' ORDER BY external_key)) FROM public.driver_product_demo_targets")->fetchColumn();
        runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'migrate:rollback', '--database=owner_a', '--path='.$upgradePath, '--batch=3', '--force']);
        runGeneratedConsumer($consumer, [PHP_BINARY, 'artisan', 'migrate:rollback', '--database=owner_a', '--path='.$paths['article'], '--batch=1', '--force']);
        expect(PgTarget::query($owner, "SELECT to_regclass('public.driver_article_demo_targets')")->fetchColumn())->toBeNull()
            ->and(PgTarget::query($owner, "SELECT md5(string_agg(external_key || ':' || panel, ',' ORDER BY external_key)) FROM public.driver_product_demo_targets")->fetchColumn())->toBe($productBefore)
            ->and(PgTarget::query($owner, 'SELECT sentinel FROM public.p38_host')->fetchColumn())->toBe('preserved');
        $productAfter = Scenario::object(json_decode(runGeneratedConsumer($consumer, [PHP_BINARY, 'models.php', 'product_after']), true, flags: JSON_THROW_ON_ERROR));
        expect($productAfter)->toBe(['enabled' => true, 'connection' => 'owner_b'])
            ->and(file_get_contents($articleProvider))->toContain('P3.8 consumer provider marker')
            ->and(file_get_contents($modelPath))->toContain('P3.8 consumer model marker');
        fwrite(STDERR, 'Product checksum before/after Article rollback='.$productBefore.PHP_EOL);
        fwrite(STDERR, "SQL assertions: both panels' five tables exist in owner; decoy remains empty\n");
    } finally {
        $files->deleteDirectory($root);
        $tables = PgTarget::query($owner, "SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
        $owned = [];
        foreach ($tables as $table) {
            if (is_string($table) && preg_match($ownedPattern, $table) === 1) {
                $owned[] = 'public."'.$table.'"';
            }
        }
        if ($owned !== []) {
            $owner->exec('DROP TABLE '.implode(', ', $owned));
        }
    }
});
