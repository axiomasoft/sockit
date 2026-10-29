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
use SocKit\Internal\Console\PanelMigrationsCommand;
use SocKit\Internal\Generation\GenerationPlan;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\Internal\MigrationPlanner;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\SocKitServiceProvider;
use SocKit\Tests\Fixtures\Runtime\OutboxModule;
use SocKit\Tests\Fixtures\Runtime\RuntimeSurface;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Filesystem root and PDO targets are owned before the first mutation. Uses real Laravel commands. */
final class MigrationFixture
{
    public readonly StoragePgFixture $pg;

    public readonly string $root;

    public Application $app;

    public Manager $capsule;

    private ClassLoader $loader;

    private mixed $previousDb;

    public function __construct(bool $selected = false, int $version = 1)
    {
        $this->pg = new StoragePgFixture;
        $this->previousDb = DB::getFacadeRoot();
        $this->root = sys_get_temp_dir().'/sockit-migrations-'.bin2hex(random_bytes(12));
        if (! mkdir($this->root, 0700)) {
            throw new \RuntimeException('Cannot create owned migration consumer');
        }
        file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
        mkdir($this->root.'/app');
        mkdir($this->root.'/app/Panels');
        file_put_contents($this->root.'/app/Panels/CustomProvider.php', "<?php\n// consumer sentinel: custom provider\n");
        $this->loader = new ClassLoader;
        $this->loader->addPsr4('App\\', $this->root.'/app');
        $this->loader->register();
        $this->pg->owner->exec('CREATE SCHEMA panel_a');
        $this->pg->owner->exec('CREATE SCHEMA panel_b');
        $this->pg->owner->exec('CREATE TABLE public.p36_host (sentinel text)');
        $this->pg->owner->exec("INSERT INTO public.p36_host VALUES ('host')");
        $this->boot(selected: $selected, version: $version);
    }

    public function boot(bool $selected = false, int $version = 1, string $prefix = 'article_', string $contribution = '', bool $enableModule = true, string $variant = '', bool $consumers = false): void
    {
        $this->app = new Application($this->root);
        $this->app->instance('env', 'testing');
        $connection = $this->pg->connection(name: 'owner_a', prefix: 'driver_');
        $config = SchemaValues::map($connection->getConfig());
        $observed = PgTarget::query($this->pg->owner, 'SELECT host(inet_server_addr()) AS host, inet_server_port() AS port')->fetch(\PDO::FETCH_ASSOC);
        $this->app->instance('config', new Repository(['app' => ['env' => 'testing'], 'sockit' => ['panels' => [MigrationPanel::class, ProductMigrationPanel::class],
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
        $result = $this->console(new PanelMigrationsCommand, ['command' => 'sockit:panel:migrations', 'panel' => $panel, '--upgrade' => $upgrade, '--dry-run' => $dryRun]);
        $data = Scenario::object(json_decode(trim($result['output']), true, flags: JSON_THROW_ON_ERROR));

        return ['exit' => $result['exit'], 'data' => $data];
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
