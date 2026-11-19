<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\PanelConsumer;

use Illuminate\Filesystem\Filesystem;
use PDO;
use SocKit\Tests\Fixtures\ConsumerPackages;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Process\Process;

/** Actual copied-package Composer consumer. No root autoload or Testbench in child processes. */
final class SocialConsumer
{
    public readonly string $root;

    public readonly string $consumer;

    public readonly PDO $owner;

    public readonly PDO $product;

    public readonly PDO $decoy;

    /** @var array<string, string> */
    public array $migrations = [];

    /** @var array<string, string> */
    private array $frozen = [];

    public function __construct(public readonly bool $separate = false)
    {
        $target = PgTarget::fromEnvironment();
        $this->owner = $target->connect();
        $this->decoy = $target->connect(decoy: true);
        $this->product = $separate ? $this->decoy : $this->owner;
        $this->root = sys_get_temp_dir().'/sockit-p48-'.bin2hex(random_bytes(16));
        if (! mkdir($this->root, 0700)) {
            throw new \RuntimeException('Cannot create owned consumer root');
        }
        $this->consumer = $this->root.'/tests/Fixtures/PanelConsumer';
        try {
            $project = dirname(__DIR__, 3);
            $files = new Filesystem;
            $files->ensureDirectoryExists($this->root.'/packages');
            $files->ensureDirectoryExists($this->root.'/tests/Fixtures');
            ConsumerPackages::copy($files, $project, $this->root, social: true);
            $files->copyDirectory(__DIR__, $this->consumer);
            $files->ensureDirectoryExists($this->consumer.'/bootstrap/cache');
            $files->ensureDirectoryExists($this->consumer.'/storage/framework/views');
            $observed = PgTarget::query($this->owner, 'SELECT host(inet_server_addr()) AS host, inet_server_port() AS port')->fetch(PDO::FETCH_ASSOC);
            $path = $this->consumer.'/app/PgTarget.php';
            file_put_contents($path, str_replace("'cluster' => 'test-'", "'aliases' => [".var_export($observed, true)."], 'cluster' => 'test-'", (string) file_get_contents($path)));
            if ($separate) {
                $path = $this->consumer.'/config/database.php';
                file_put_contents($path, str_replace("'owner_b' => \$target->configuration()", "'owner_b' => \$target->configuration(decoy: true)", (string) file_get_contents($path)));
            }
            file_put_contents($this->consumer.'/bootstrap/providers.php', "<?php return [\\Acme\\Social\\Provider::class];\n");
            file_put_contents($this->consumer.'/config/sockit.php', <<<'CONFIG'
<?php
return ['panels' => [], 'modules' => ['enabled' => ['sockit.reactions']],
    'surfaces' => [],
    'resolvers' => ['actor' => \Acme\Runtime\Actor::class, 'tenant' => \Acme\Runtime\Tenant::class,
        'contour' => \Acme\Runtime\Host::class, 'groups' => \Acme\Runtime\Host::class,
        'surface_policy' => \Acme\Social\Policy::class, 'targets' => \Acme\Social\Target::class,
        'authorizer' => \Acme\Runtime\AuthorityRouter::class, 'audience' => \SocKit\Audience\AudienceEvaluator::class],
    'storage' => ['deployments' => ['owner_a' => \Acme\PgTarget::fromEnvironment()->deployment(), 'owner_b' => \Acme\PgTarget::fromEnvironment()->deployment()]]];
CONFIG);
            $this->run(['composer', 'install', '--no-dev', '--no-interaction', '--no-scripts']);
            $installed = $this->consumer.'/vendor/axiomasoft/sockit';
            expect(is_link($installed))->toBeFalse();
            $graph = Scenario::object(json_decode((string) file_get_contents($this->consumer.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR));
            $packages = $graph['packages'];
            if (! is_array($packages)) {
                throw new \RuntimeException('Installed package graph missing');
            }
            $names = array_column($packages, 'name');
            fwrite(STDERR, 'P4.8 installed packages='.json_encode($names, JSON_THROW_ON_ERROR).PHP_EOL);
            foreach (['axiomasoft/sockit-monorepo', 'axiomasoft/chatom', 'axiomasoft/forum', 'axiomasoft/azguard', 'axiomasoft/vaulter', 'orchestra/testbench'] as $forbidden) {
                expect($names)->not->toContain($forbidden);
            }
            $fingerprints = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($project.'/packages/core', \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $relative = substr($file->getPathname(), strlen($project.'/packages/core/'));
                    $fingerprints[$relative] = hash_file('sha256', $file->getPathname());
                    expect(hash_file('sha256', $installed.'/'.$relative))->toBe($fingerprints[$relative]);
                }
            }
            ksort($fingerprints);
            fwrite(STDERR, 'P4.8 installed source fingerprint='.hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR)).' separate_databases='.(int) $separate.PHP_EOL);
            $this->artisan(['package:discover']);
        } catch (\Throwable $error) {
            new Filesystem()->deleteDirectory($this->root);
            throw $error;
        }
    }

    /** @param list<string> $command */
    public function run(array $command, int $expected = 0): string
    {
        $process = new Process($command, cwd: $this->consumer, env: ['APP_ENV' => 'testing', 'COMPOSER_NO_INTERACTION' => '1']);
        $process->setTimeout(300);
        $exit = $process->run();
        $stdout = $process->getOutput();
        $decoded = json_decode($stdout, true);
        if (is_array($decoded) && isset($decoded['components'])) {
            // The full schema is already in generated, hashed snapshots. Keep command logs reviewable.
            unset($decoded['components']);
            $stdout = json_encode($decoded, JSON_THROW_ON_ERROR).' stdout_sha256='.hash('sha256', $stdout);
        }
        fwrite(STDERR, 'P4.8 consumer cwd='.$this->consumer.' command='.json_encode($command, JSON_THROW_ON_ERROR).' exit='.$exit.PHP_EOL.$stdout.$process->getErrorOutput().PHP_EOL);
        expect($exit)->toBe($expected);

        return $process->getOutput();
    }

    /** @param list<string> $command
     * @return array<string, mixed> */
    public function json(array $command, int $expected = 0): array
    {
        return Scenario::object(json_decode($this->run($command, $expected), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $args */
    public function artisan(array $args, int $expected = 0): string
    {
        return $this->run([PHP_BINARY, 'artisan', ...$args], $expected);
    }

    /** @return array<string, string> */
    private function tree(): array
    {
        $hashes = [];
        foreach (['app/Panels', 'app/Models', 'app/SocKit/Generated', 'database'] as $path) {
            if (! is_dir($this->consumer.'/'.$path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->consumer.'/'.$path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $hashes[substr($file->getPathname(), strlen($this->consumer) + 1)] = (string) hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($hashes);

        return $hashes;
    }

    public function generate(): void
    {
        foreach (['Article' => 'article', 'Product' => 'product'] as $name => $slug) {
            $args = ['sockit:make-panel', $name, '--id=acme.'.$slug, '--host='.$slug, '--modules=sockit.reactions', '--prefix='.$slug.'_', '--connection=owner_'.($slug === 'article' ? 'a' : 'b')];
            $before = $this->tree();
            $dry = $this->json([PHP_BINARY, 'artisan', ...$args, '--dry-run']);
            expect($dry['status'])->toBe('dry_run')->and($this->tree())->toBe($before);
            $result = $this->json([PHP_BINARY, 'artisan', ...$args]);
            expect($result['status'])->toBe('installed');
            $generated = $this->tree();
            $this->artisan($args, expected: 1);
            expect($this->tree())->toBe($generated);
        }
        $before = $this->tree();
        $this->artisan(['sockit:make-panel', 'Invalid', '--id=acme.invalid', '--host=article', '--modules=reactions', '--prefix=invalid_', '--connection=owner_a'], expected: 1);
        expect($this->tree())->toBe($before);
        $path = $this->consumer.'/config/sockit.php';
        $source = str_replace("'panels' => []", "'panels' => [\\Acme\\Panels\\ArticlePanelProvider::class, \\Acme\\Panels\\ProductPanelProvider::class]", (string) file_get_contents($path));
        $source = str_replace("'surfaces' => []", "'surfaces' => [\\Acme\\Social\\ArticleSurface::class, \\Acme\\Social\\ProductSurface::class]", $source);
        file_put_contents($path, $source);
        // Consumer extension uses a real custom class, preserving the immutable binding.
        $model = $this->consumer.'/app/Models/SocKit/Article/Reaction.php';
        $source = str_replace('class Reaction extends', 'class CustomReaction extends', (string) file_get_contents($model));
        file_put_contents(dirname($model).'/CustomReaction.php', $source."\n// P4.8 custom model\n");
        $provider = $this->consumer.'/app/Panels/ArticlePanelProvider.php';
        file_put_contents($provider, str_replace(['Article\\Reaction::class', "->hostTypes('article')"], ['Article\\CustomReaction::class', "->hostTypes('article', 'product')"], (string) file_get_contents($provider))."\n// P4.8 custom provider\n");
        $beforeBoot = $this->json([PHP_BINARY, 'social.php', 'boot']);
        $this->artisan(['config:cache']);
        $afterBoot = $this->json([PHP_BINARY, 'social.php', 'boot']);
        expect($afterBoot)->toBe($beforeBoot)->and($afterBoot['connections'])->toBe(0)->and($afterBoot['queries'])->toBe(0);
        foreach (['article', 'product'] as $slug) {
            $args = [PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.'.$slug];
            $before = $this->tree();
            expect($this->json([...$args, '--dry-run'])['status'])->toBe('dry_run')->and($this->tree())->toBe($before);
            $result = $this->json($args);
            expect($result['status'])->toBe('generated');
            $this->migrations[$slug] = Scenario::text($result['migration']);
            $generated = $this->tree();
            $this->run($args, expected: 1);
            expect($this->json([...$args, '--upgrade'])['status'])->toBe('no_op')->and($this->tree())->toBe($generated);
        }
        $this->frozen = $this->tree();
    }

    public function migrate(): void
    {
        foreach ($this->migrations as $slug => $path) {
            $this->artisan(['migrate', '--database=owner_'.($slug === 'article' ? 'a' : 'b'), '--path='.$path, '--force']);
        }
        foreach ([$this->owner, ...($this->separate ? [$this->product] : [])] as $pdo) {
            $pdo->exec('CREATE TABLE public.p48_host (sentinel text NOT NULL)');
            $pdo->exec("INSERT INTO public.p48_host VALUES ('preserved')");
            $pdo->exec('CREATE TABLE public.driver_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
            $pdo->exec('INSERT INTO public.driver_envelope_authority (allowed) VALUES (true)');
            $pdo->exec('CREATE TABLE public.driver_p48_targets (type text NOT NULL, id text NOT NULL, incarnation bigint NOT NULL, generation text NOT NULL, visible boolean NOT NULL, PRIMARY KEY(type,id))');
            $pdo->exec("INSERT INTO public.driver_p48_targets VALUES ('article','42',1,'1',true),('product','42',1,'1',true)");
        }
        foreach (['article' => $this->owner, 'product' => $this->product] as $slug => $pdo) {
            foreach (['surface_instances', 'receipts', 'outbox', 'tombstones', 'reactions', 'reaction_counts'] as $entity) {
                expect(PgTarget::query($pdo, "SELECT to_regclass('public.driver_{$slug}_{$entity}')")->fetchColumn())->not->toBeNull();
            }
            foreach (['posts', 'post_revisions'] as $entity) {
                expect(PgTarget::query($pdo, "SELECT to_regclass('public.driver_{$slug}_{$entity}')")->fetchColumn())->toBeNull();
            }
        }
        if (! $this->separate) {
            expect(PgTarget::query($this->decoy, "SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn())->toBe(0);
        } else {
            expect(PgTarget::query($this->owner, "SELECT to_regclass('public.driver_product_reactions')")->fetchColumn())->toBeNull();
            expect(PgTarget::query($this->product, "SELECT to_regclass('public.driver_article_reactions')")->fetchColumn())->toBeNull();
        }
        fwrite(STDERR, "P4.8 SQL catalog: production reactions/kernel tables present; posts tables absent; default/foreign storage untouched\n");
    }

    public function smoke(): void
    {
        $result = $this->json([PHP_BINARY, 'social.php', 'smoke']);
        expect($result['assertions'])->toBe(['public_surfaces', 'add_replay', 'panel_isolation', 'alias_incarnation_isolation', 'remove_cas', 'current_guards', 'atomic_rollback', 'models_queue']);
        $payload = Scenario::text($result['queue']);
        expect($this->json([PHP_BINARY, 'social.php', 'restore', $payload]))->toBe(['panel' => 'acme.article', 'connection' => 'owner_a', 'custom' => true]);
    }

    /** @return array<string, string> */
    public function state(string $slug): array
    {
        $pdo = $slug === 'article' ? $this->owner : $this->product;
        $state = [];
        foreach (['surface_instances', 'receipts', 'outbox', 'tombstones', 'reactions', 'reaction_counts'] as $entity) {
            $state[$entity] = hash('sha256', Scenario::text(PgTarget::query($pdo, "SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text,'[]') FROM public.driver_{$slug}_{$entity} t")->fetchColumn()));
        }
        fwrite(STDERR, 'P4.8 SQL state '.$slug.' '.json_encode($state, JSON_THROW_ON_ERROR).PHP_EOL);

        return $state;
    }

    private function assertFrozen(): void
    {
        $current = $this->tree();
        foreach ($this->frozen as $path => $hash) {
            expect($current[$path])->toBe($hash);
        }
        fwrite(STDERR, 'P4.8 frozen/custom SHA256 '.json_encode($this->frozen, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function clearConfig(): void
    {
        // Laravel boots providers before config:clear. Remove our own stale cache first when
        // deploying a changed provider graph, then exercise the real command/fresh bootstrap.
        $cache = $this->consumer.'/bootstrap/cache/config.php';
        if (is_file($cache) && ! unlink($cache)) {
            throw new \RuntimeException('Cannot remove owned stale config cache');
        }
        $this->artisan(['config:clear']);
    }

    public function upgrade(): void
    {
        $article = $this->state('article');
        $product = $this->state('product');
        $provider = $this->consumer.'/app/Panels/ArticlePanelProvider.php';
        $original = (string) file_get_contents($provider);
        $enabled = str_replace("->modules('sockit.reactions')", "->modules('sockit.reactions', 'sockit.posts', 'acme.runtime')", $original);
        expect($enabled)->not->toBe($original);
        file_put_contents($provider, $enabled);
        $this->frozen['app/Panels/ArticlePanelProvider.php'] = (string) hash_file('sha256', $provider);
        $path = $this->consumer.'/config/sockit.php';
        file_put_contents($path, str_replace("'enabled' => ['sockit.reactions']", "'enabled' => ['sockit.reactions', 'sockit.posts', 'acme.runtime']", (string) file_get_contents($path)));
        file_put_contents($this->consumer.'/config/social.php', "<?php return ['posts_offered' => true];\n");
        $surface = $this->consumer.'/app/Social/ArticleSurface.php';
        $surfaceOriginal = (string) file_get_contents($surface);
        $surfaceEnabled = str_replace("->features('reactions')", "->features('reactions', 'posts')", $surfaceOriginal);
        file_put_contents($surface, $surfaceEnabled);
        $this->clearConfig();
        $this->artisan(['config:cache']);
        $before = $this->tree();
        $args = [PHP_BINARY, 'artisan', 'sockit:panel:migrations', 'acme.article', '--upgrade'];
        expect($this->json([...$args, '--dry-run'])['status'])->toBe('dry_run')->and($this->tree())->toBe($before);
        $upgrade = $this->json($args);
        expect($upgrade['status'])->toBe('generated')->and(Scenario::object($upgrade['models']))->toHaveKeys(['posts', 'post_revisions']);
        $this->migrations['upgrade'] = Scenario::text($upgrade['migration']);
        $this->assertFrozen();
        $this->frozen = $this->tree();
        $this->artisan(['migrate', '--database=owner_a', '--path='.$this->migrations['upgrade'], '--force']);
        expect($this->json([PHP_BINARY, 'social.php', 'posts-on'])['post_capability'])->toBeTrue();
        expect($this->state('article'))->toBe($article)->and($this->state('product'))->toBe($product);
        expect(PgTarget::query($this->owner, "SELECT to_regclass('public.driver_article_posts')")->fetchColumn())->not->toBeNull();
        expect(PgTarget::query($this->product, "SELECT to_regclass('public.driver_product_posts')")->fetchColumn())->toBeNull();
        $models = Scenario::object($upgrade['models']);
        expect($this->json([PHP_BINARY, 'social.php', 'seed-post', Scenario::text($models['posts']), Scenario::text($models['post_revisions'])])['relations'])->toBeTrue();
        $article = $this->state('article');
        $postState = $this->postsState();
        expect(PgTarget::query($this->owner, "SELECT count(*) FROM public.driver_article_outbox WHERE deliveries::jsonb->'acme.inbox'->>'status' = 'pending'")->fetchColumn())->toBe(1);
        $installed = $this->tree();
        expect($this->json($args)['status'])->toBe('no_op')->and($this->tree())->toBe($installed);
        // Disable the offered module on Article while leaving the Product panel operational.
        file_put_contents($provider, $original);
        file_put_contents($surface, $surfaceOriginal);
        $this->clearConfig();
        $this->artisan(['config:cache']);
        expect($this->json([PHP_BINARY, 'social.php', 'posts-off'])['post_capability'])->toBeFalse();
        expect($this->json($args)['status'])->toBe('no_op')->and($this->state('article'))->toBe($article)->and($this->state('product'))->toBe($product)->and($this->postsState())->toBe($postState);
        expect(PgTarget::query($this->owner, "SELECT to_regclass('public.driver_article_posts')")->fetchColumn())->not->toBeNull();
        file_put_contents($provider, $enabled);
        file_put_contents($surface, $surfaceEnabled);
        $this->clearConfig();
        $this->artisan(['config:cache']);
        expect($this->json($args)['status'])->toBe('no_op')->and($this->json([PHP_BINARY, 'social.php', 'posts-on'])['post_capability'])->toBeTrue()->and($this->tree())->toBe($installed);
        $this->assertFrozen();
        expect($this->postsState())->toBe($postState);
        expect(PgTarget::query($this->owner, "SELECT count(*) FROM public.driver_article_outbox WHERE deliveries::jsonb->'acme.inbox'->>'status' = 'pending'")->fetchColumn())->toBe(1);
        // Disable reactions too: capability denial must retain memberships, counters and pending events.
        $surface = $this->consumer.'/app/Social/ArticleSurface.php';
        $surfaceSource = (string) file_get_contents($surface);
        file_put_contents($surface, str_replace("->features('reactions', 'posts')", "->features('posts')", $surfaceSource));
        file_put_contents($provider, str_replace("->modules('sockit.reactions', 'sockit.posts', 'acme.runtime')", "->modules('sockit.posts', 'acme.runtime')", $enabled));
        $this->clearConfig();
        expect($this->json([PHP_BINARY, 'social.php', 'reactions-off'])['denied'])->toBeTrue();
        expect($this->state('article'))->toBe($article)->and($this->state('product'))->toBe($product);
        file_put_contents($surface, $surfaceSource);
        file_put_contents($provider, $enabled);
        expect($this->json([PHP_BINARY, 'social.php', 'read'])['like'])->toBe(1);
        expect($this->state('article'))->toBe($article)->and($this->state('product'))->toBe($product);
        // Mutable config now disagrees with the frozen snapshot. Rollback must still use its old binding.
        file_put_contents($provider, str_replace("'article_'", "'changed_'", $original));
        file_put_contents($surface, $surfaceOriginal);
        $this->clearConfig();
        $schemaPath = $this->consumer.'/vendor/axiomasoft/sockit-reactions/src/Internal/InitialSchema.php';
        $schemaSource = (string) file_get_contents($schemaPath);
        $changed = str_replace("id: 'sockit.reactions', version: 1", "id: 'sockit.reactions', version: 2", $schemaSource);
        $changed = str_replace('        ]);', "            new SchemaStep(id: 'future_note', version: 2, entity: 'reactions', columns: ['future_note' => new SchemaColumn(type: 'text', nullable: true)]),\n        ]);", $changed);
        expect($changed)->not->toBe($schemaSource);
        file_put_contents($schemaPath, $changed);
        fwrite(STDERR, 'P4.8 consumer-only version drift reactions v1 -> v2 source_sha256='.hash('sha256', $changed).PHP_EOL);
        $before = $this->tree();
        $conflict = $this->json($args, expected: 1);
        expect($conflict['status'])->toBe('failed')->and($conflict['problem'])->toBe('MIGRATION_BINDING_CHANGED')->and($this->tree())->toBe($before);
        $this->assertHistory();
    }

    /** @return array<string, string> */
    private function postsState(): array
    {
        $state = [];
        foreach (['posts', 'post_revisions'] as $entity) {
            $state[$entity] = hash('sha256', Scenario::text(PgTarget::query($this->owner, "SELECT jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text)::text FROM public.driver_article_{$entity} t")->fetchColumn()));
        }
        fwrite(STDERR, 'P4.8 SQL posts state '.json_encode($state, JSON_THROW_ON_ERROR).PHP_EOL);

        return $state;
    }

    private function assertHistory(): void
    {
        foreach ($this->migrations as $path) {
            if (isset($this->frozen[$path])) {
                expect(hash_file('sha256', $this->consumer.'/'.$path))->toBe($this->frozen[$path]);
            }
        }
    }

    public function rollback(): void
    {
        $product = $this->state('product');
        if (isset($this->migrations['upgrade'])) {
            $this->rollbackPath($this->migrations['upgrade']);
        } else {
            $provider = $this->consumer.'/app/Panels/ArticlePanelProvider.php';
            file_put_contents($provider, str_replace("'article_'", "'changed_'", (string) file_get_contents($provider)));
            $this->clearConfig();
        }
        $this->rollbackPath($this->migrations['article']);
        foreach (['surface_instances', 'receipts', 'outbox', 'tombstones', 'reactions', 'reaction_counts', 'posts', 'post_revisions'] as $entity) {
            expect(PgTarget::query($this->owner, "SELECT to_regclass('public.driver_article_{$entity}')")->fetchColumn())->toBeNull();
        }
        expect($this->state('product'))->toBe($product);
        foreach ([$this->owner, $this->product] as $pdo) {
            expect(PgTarget::query($pdo, 'SELECT sentinel FROM public.p48_host')->fetchColumn())->toBe('preserved');
            expect(PgTarget::query($pdo, 'SELECT count(*) FROM public.driver_p48_targets')->fetchColumn())->toBe(2);
        }
        expect($this->json([PHP_BINARY, 'social.php', 'product-after'])['like'])->toBe(1);
        $this->assertHistory();
        fwrite(STDERR, "P4.8 frozen Article rollback: Product tables/data/receipts/outbox and host sentinels preserved; Product public writes still work\n");
    }

    private function rollbackPath(string $path): void
    {
        $migration = basename($path, '.php');
        $statement = $this->owner->prepare('SELECT batch FROM public.driver_migrations WHERE migration = ?');
        $statement->execute([$migration]);
        $batch = $statement->fetchColumn();
        if (! is_int($batch)) {
            throw new \RuntimeException('Missing exact owned migration batch');
        }
        $this->artisan(['migrate:rollback', '--database=owner_a', '--path='.$path, '--batch='.$batch, '--force']);
    }

    public function cleanup(): void
    {
        new Filesystem()->deleteDirectory($this->root);
        // All SQL cleanup is confined to the invocation-owned databases; wrapper removes the container.
        foreach ([$this->owner, ...($this->separate ? [$this->product] : [])] as $pdo) {
            $tables = PgTarget::query($pdo, "SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                if (! is_string($table) || preg_match('/^(driver_|p48_)[a-z0-9_]+$/D', $table) !== 1) {
                    throw new \RuntimeException('Unexpected table in owned consumer database');
                }
                $pdo->exec('DROP TABLE IF EXISTS public."'.$table.'" CASCADE');
            }
        }
    }
}
