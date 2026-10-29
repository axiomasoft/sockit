<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Composer\Autoload\ClassLoader;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use SocKit\Internal\Console\MakePanelCommand;
use SocKit\Internal\Console\PanelMigrationsCommand;
use SocKit\Internal\Generation\GenerationPaths;
use SocKit\Internal\Generation\GenerationPlan;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\Internal\MigrationHistory;
use SocKit\Schema\Internal\MigrationPlanner;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\ModelEmitter;
use SocKit\Schema\PanelSchema;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Runtime\OutboxModule;
use SocKit\Tests\Fixtures\Runtime\RuntimeSurface;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/** Filesystem root and PDO targets are owned before the first mutation. Uses real Laravel commands. */
final class MigrationFixture
{
    public readonly StoragePgFixture $pg;

    public readonly string $root;

    public Application $app;

    public Manager $capsule;

    private readonly ClassLoader $loader;

    private readonly mixed $previousDb;

    /** @var array<string, mixed> */
    private array $bootOptions = [];

    public function __construct(bool $selected = false, int $version = 1, ?string $root = null, ?string $namespace = null, string $directory = 'app')
    {
        $this->pg = new StoragePgFixture;
        $this->previousDb = DB::getFacadeRoot();
        $this->root = $root ?? sys_get_temp_dir().'/sockit-migrations-'.bin2hex(random_bytes(12));
        if ($root === null && ! mkdir($this->root, 0700)) {
            throw new \RuntimeException('Cannot create owned migration consumer');
        }
        if ($root === null) {
            $namespace ??= 'App\\Test'.bin2hex(random_bytes(8)).'\\';
            file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => [$namespace => $directory.'/']]], JSON_THROW_ON_ERROR));
            mkdir($this->root.'/'.$directory);
            mkdir($this->root.'/'.$directory.'/Panels');
            file_put_contents($this->root.'/'.$directory.'/Panels/CustomProvider.php', "<?php\n// consumer sentinel: custom provider\n");
        }
        $mapping = GenerationPaths::mapping($this->root);
        $this->loader = new ClassLoader;
        $this->loader->addPsr4($mapping['namespace'], $this->root.'/'.$mapping['directory']);
        $this->loader->register();
        if ($root !== null) {
            return;
        }
        $this->pg->owner->exec('CREATE SCHEMA panel_a');
        $this->pg->owner->exec('CREATE SCHEMA panel_b');
        $this->pg->owner->exec('CREATE TABLE public.p36_host (sentinel text)');
        $this->pg->owner->exec("INSERT INTO public.p36_host VALUES ('host')");
        $this->boot(selected: $selected, version: $version);
    }

    public function boot(bool $selected = false, int $version = 1, string $prefix = 'article_', string $contribution = '', bool $enableModule = true, string $variant = '', bool $consumers = false, string $generated = ''): void
    {
        $this->bootOptions = ['selected' => $selected, 'version' => $version, 'prefix' => $prefix, 'contribution' => $contribution, 'enableModule' => $enableModule, 'variant' => $variant, 'consumers' => $consumers, 'generated' => $generated];
        $this->app = new Application($this->root);
        $this->app->instance('env', 'testing');
        $connection = $this->pg->connection(name: 'owner_a', prefix: 'driver_');
        $config = SchemaValues::map($connection->getConfig());
        $observed = PgTarget::query($this->pg->owner, 'SELECT host(inet_server_addr()) AS host, inet_server_port() AS port')->fetch(\PDO::FETCH_ASSOC);
        $this->app->instance('config', new Repository(['app' => ['env' => 'testing'], 'sockit' => ['panels' => [$generated !== '' ? $generated : MigrationPanel::class, ProductMigrationPanel::class],
            'surfaces' => [RuntimeSurface::class], 'modules' => ['enabled' => [...($enableModule ? ['acme.demo-targets'] : []), 'acme.runtime']], 'storage' => ['deployments' => [
                'owner_a' => ['cluster' => 'owned_pg', 'endpoint' => ['host' => $config['host'], 'port' => $config['port']], 'aliases' => [$observed]],
            ]]], 'database' => ['default' => 'owner_a', 'migrations' => ['table' => 'migrations']],
            'schema_fixture' => ['contribution' => $contribution !== '' ? $contribution : ($version === 1 ? DemoTargetsV1::class : DemoTargetsV2::class), 'variant' => $variant]]));
        $this->app->instance(MigrationPanel::class, new MigrationPanel(selected: $selected, prefix: $prefix, consumers: $consumers));
        $this->app->instance(RuntimeSurface::class, new RuntimeSurface('acme.article'));
        $this->app->instance('events', new Dispatcher($this->app));
        $this->capsule = new Manager($this->app);
        $this->capsule->addConnection([...$config, 'prefix' => 'driver_'], 'owner_a');
        $this->capsule->addConnection(SchemaValues::map($this->pg->connection(name: 'decoy')->getConfig()), 'decoy');
        $this->capsule->getDatabaseManager()->setDefaultConnection('owner_a');
        $this->app->instance('db', $this->capsule->getDatabaseManager());
        $this->app->instance(ConnectionResolverInterface::class, $this->capsule->getDatabaseManager());
        $this->capsule->getConnection('owner_a')->setTransactionManager(new DatabaseTransactionsManager);
        DB::swap($this->capsule->getDatabaseManager());
        $this->app->register(SocKitServiceProvider::class);
        $this->app->register(SchemaProvider::class);
        Modules::add(app: $this->app, modules: OutboxModule::class);
        $this->app->boot();
    }

    /** @return array{exit:int, data:array<string, mixed>} */
    public function command(bool $upgrade = false, bool $dryRun = false, string $panel = 'acme.article'): array
    {
        $data = $this->child(['upgrade' => $upgrade, 'dryRun' => $dryRun, 'panel' => $panel]);
        $exit = $data['exit'];
        if (! is_int($exit)) {
            throw new \RuntimeException('Invalid migration command exit');
        }

        return ['exit' => $exit, 'data' => Scenario::object($data['data'])];
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed> */
    public function child(array $options): array
    {
        $process = new Process([PHP_BINARY, __DIR__.'/migration-command.php', $this->root, json_encode($this->bootOptions, JSON_THROW_ON_ERROR),
            json_encode($options, JSON_THROW_ON_ERROR)]);
        $process->setTimeout(30);
        $process->mustRun();

        return Scenario::object(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array{exit:int, data:array<string, mixed>} */
    public function commandLocal(bool $upgrade = false, bool $dryRun = false, string $panel = 'acme.article'): array
    {
        $result = $this->console(new PanelMigrationsCommand, ['command' => 'sockit:panel:migrations', 'panel' => $panel, '--upgrade' => $upgrade, '--dry-run' => $dryRun]);
        $data = Scenario::object(json_decode(trim($result['output']), true, flags: JSON_THROW_ON_ERROR));

        return ['exit' => $result['exit'], 'data' => $data];
    }

    /** @return array{exit:int, data:array<string, mixed>} */
    public function makePanel(bool $selected = false): array
    {
        // A separate empty panel registry is compiled before invoking the real make-panel CLI.
        $app = $this->app;
        $empty = new Application($this->root);
        $empty->instance('env', 'testing');
        $config = new Repository($app->make('config')->all());
        $config->set('sockit.panels', []);
        $config->set('sockit.surfaces', []);
        $empty->instance('config', $config);
        $empty->register(SocKitServiceProvider::class);
        $empty->register(SchemaProvider::class);
        Modules::add($empty, OutboxModule::class);
        $empty->boot();
        $this->app = $empty;
        $result = $this->console(new MakePanelCommand, ['command' => 'sockit:make-panel', 'name' => 'Article', '--id' => 'acme.article', '--host' => 'article',
            '--modules' => $selected ? 'acme.demo-targets' : '', '--prefix' => 'article_', '--connection' => 'owner_a', '--schema' => 'panel_a']);
        $this->app = $app;

        return ['exit' => $result['exit'], 'data' => Scenario::object(json_decode(trim($result['output']), true, flags: JSON_THROW_ON_ERROR))];
    }

    public function generated(string $class): void
    {
        $this->bootOptions['generated'] = $class;
    }

    public function schema(): PanelSchema
    {
        $schema = new PanelSchemaCompiler()->compile(panel: $this->app->make(PanelDefinitions::class)->get(new PanelId('acme.article')), components: $this->app->make(Modules::class)->schemaComponents());
        $history = new MigrationHistory()->read(root: $this->root, panel: 'acme.article');

        return $history === [] ? $schema : MigrationHistory::union(previous: Scenario::object($history[array_key_last($history)]['schema']), desired: $schema);
    }

    /** Simulate the consumer explicitly applying required repairs, preserving every other byte. */
    public function repairModels(bool $relations = true): void
    {
        $schema = MigrationHistory::schema(Scenario::object($this->child(['operation' => 'schema'])['schema']));
        $methods = new ModelEmitter()->methods($schema);
        $mapping = GenerationPaths::mapping($this->root);
        foreach (new ModelEmitter()->render($schema) as $class => $expected) {
            $path = $this->root.'/'.$mapping['directory'].'/'.str_replace('\\', '/', substr($class, strlen($mapping['namespace']))).'.php';
            if (! is_file($path)) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (preg_match('/public const array BINDING = .*?;\n/s', $expected, $match) !== 1) {
                throw new \RuntimeException('Missing expected model binding');
            }
            $source = preg_replace_callback('/public const array BINDING = .*?;\n/s', static fn (): string => $match[0], $source);
            if (! is_string($source)) {
                throw new \RuntimeException('Invalid model source');
            }
            $entity = array_search($class, $schema->storage->models, true);
            foreach ($relations ? ($methods[$entity] ?? []) : [] as $method => $definition) {
                if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/i', $source) !== 1) {
                    $position = (int) strrpos($source, '}');
                    $source = substr($source, 0, $position).$definition."\n".substr($source, $position);
                }
            }
            file_put_contents($path, $source);
        }
    }

    /** @return array{exit:int, output:string} */
    public function migrate(bool $rollback = false, int $steps = 1): array
    {
        $repository = new DatabaseMigrationRepository($this->capsule->getDatabaseManager(), 'migrations');
        $repository->setSource('owner_a');
        if (! $repository->repositoryExists()) {
            $repository->createRepository();
        }
        $migrator = new Migrator($repository, $this->capsule->getDatabaseManager(), new Filesystem, new Dispatcher($this->app));
        $command = $rollback ? new RollbackCommand($migrator) : new MigrateCommand($migrator, new Dispatcher($this->app));

        return $this->console($command, ['command' => $rollback ? 'migrate:rollback' : 'migrate', '--database' => 'owner_a', '--force' => true, ...($rollback ? ['--step' => $steps] : [])]);
    }

    /** @param array<string, mixed> $input
     * @return array{exit:int, output:string} */
    private function console(Command $command, array $input): array
    {
        $command->setLaravel($this->app);
        $cli = new \Symfony\Component\Console\Application;
        $cli->setAutoExit(false);
        $cli->setCatchExceptions(false);
        $cli->addCommand($command);
        $output = new BufferedOutput;
        $exit = $cli->run(new ArrayInput($input), $output);

        return ['exit' => $exit, 'output' => $output->fetch()];
    }

    /** @return array<string, string> */
    public function tree(): array
    {
        $tree = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($walk as $file) {
            assert($file instanceof \SplFileInfo);
            $tree[substr($file->getPathname(), strlen($this->root) + 1)] = $file->isDir() ? 'directory' : (string) hash_file('sha256', $file->getPathname());
        }
        ksort($tree);

        return $tree;
    }

    /** @return list<string> */
    public function manifests(): array
    {
        return glob($this->root.'/database/sockit/acme.article/*.json') ?: [];
    }

    public function plan(bool $upgrade = false): GenerationPlan
    {
        $desired = new PanelSchemaCompiler()->compile(panel: $this->app->make(PanelDefinitions::class)->get(new PanelId('acme.article')), components: $this->app->make(Modules::class)->schemaComponents());
        $repository = new DatabaseMigrationRepository($this->capsule->getDatabaseManager(), 'migrations');
        $repository->setSource('owner_a');

        return new MigrationPlanner()->plan(root: $this->root, desired: $desired, connection: $this->capsule->getConnection('owner_a'),
            deployment: $this->app->make('config')->get('sockit.storage.deployments.owner_a'),
            ran: $repository->repositoryExists() ? array_values(array_map(Scenario::text(...), $repository->getRan())) : [], upgrade: $upgrade);
    }

    public function cleanup(): void
    {
        $this->loader->unregister();
        new Filesystem()->deleteDirectory($this->root);
        $this->capsule->getDatabaseManager()->disconnect('owner_a');
        $this->pg->owner->exec('DROP SCHEMA panel_a CASCADE');
        $this->pg->owner->exec('DROP SCHEMA panel_b CASCADE');
        $this->pg->owner->exec('DROP TABLE public.p36_host');
        $this->pg->owner->exec('DROP TABLE IF EXISTS public.driver_migrations');
        DB::swap($this->previousDb);
    }
}
