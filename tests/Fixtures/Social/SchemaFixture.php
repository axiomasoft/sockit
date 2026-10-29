<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use SocKit\Modules\Modules;
use SocKit\Panels\Panel;
use SocKit\Schema\ModelBindings;
use SocKit\Schema\ModelEmitter;
use SocKit\Schema\PanelModel;
use SocKit\Schema\PanelSchema;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Schema\SnapshotRenderer;
use SocKit\Tests\Fixtures\Schema\LoadedSnapshot;
use SocKit\Tests\Fixtures\Schema\SnapshotFixture;
use SocKit\Tests\Fixtures\Schema\StoragePgFixture;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class SchemaFixture
{
    public readonly StoragePgFixture $pg;

    public readonly Manager $capsule;

    /** @var array<string, PanelSchema> */
    public array $schemas = [];

    /** @var array<string, LoadedSnapshot> */
    public array $migrations = [];

    /** @var list<string> */
    private array $paths = [];

    private readonly mixed $previousDb;

    /** @param list<string> $selected */
    public function __construct(array $selected = ['sockit.reactions', 'sockit.posts'])
    {
        // Exact container, loopback endpoint, database run marker and *_test verified before first DDL.
        $this->pg = new StoragePgFixture;
        $this->capsule = new Manager;
        $this->previousDb = DB::getFacadeRoot();
        $app = Selection::app(enabled: $selected);
        $app->boot();
        $components = $app->make(Modules::class)->schemaComponents();
        $namespace = 'SocKit\\Tests\\Generated\\Social'.bin2hex(random_bytes(8)).'\\';
        foreach (['a', 'b'] as $id) {
            $this->pg->owner->exec('CREATE SCHEMA social_'.$id);
            $connection = $this->pg->connection(name: 'social_'.$id, prefix: 'drv_');
            $this->capsule->addConnection([...Scenario::object($connection->getConfig()), 'prefix' => 'drv_'], 'social_'.$id);
            $panel = new Panel()->id('social.'.$id)->hostTypes('article')->connection('social_'.$id)->schema('social_'.$id)->prefix($id.'_')->modules(...$selected);
            if (in_array('sockit.posts', $selected, true)) {
                /** @var class-string $custom */
                $custom = $namespace.strtoupper($id).'CustomPost';
                $panel->tables(['posts' => 'custom_posts'])->models(['posts' => $custom]);
            }
            $schema = new PanelSchemaCompiler()->compile($panel->materialize(entities: new PanelSchemaCompiler()->entities($selected, $components)), $components, modelNamespace: $namespace);
            $this->schemas[$id] = $schema;
            $config = Scenario::object($connection->getConfig());
            $observed = $this->pg->owner->query('SELECT host(inet_server_addr()) AS host, inet_server_port() AS port');
            if ($observed === false) {
                throw new \RuntimeException('Cannot observe isolated PG endpoint');
            }
            $deployment = ['cluster' => 'owned_pg', 'endpoint' => ['host' => $config['host'], 'port' => $config['port']], 'aliases' => [$observed->fetch(\PDO::FETCH_ASSOC)]];
            $source = new SnapshotRenderer()->render($schema, $connection, $deployment);
            $path = SnapshotFixture::file($source);
            $this->paths[] = $path;
            $this->migrations[$id] = new LoadedSnapshot($path);
            foreach (new ModelEmitter()->render($schema) as $class => $source) {
                if ($class === ($schema->storage->models['posts'] ?? null)) {
                    // A host customization is preserved while the generated frozen binding remains exact.
                    $at = strrpos($source, '}');
                    assert($at !== false);
                    $source = substr($source, 0, $at)."    public function hostMarker(): string { return 'custom'; }\n".substr($source, $at);
                }
                $path = SnapshotFixture::file($source);
                $this->paths[] = $path;
                require $path;
            }
            new ModelBindings()->validate($schema);
        }
        $this->capsule->addConnection(Scenario::object($this->pg->connection()->getConfig()), 'unused_default');
        $this->capsule->getDatabaseManager()->setDefaultConnection('unused_default');
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        DB::swap($this->capsule->getDatabaseManager());
        $this->pg->owner->exec('CREATE TABLE public.p41_host (value text NOT NULL)');
        $this->pg->owner->exec("INSERT INTO public.p41_host VALUES ('host-sentinel')");
    }

    public function model(string $entity, string $id = 'a'): PanelModel
    {
        $class = $this->schemas[$id]->storage->models[$entity];
        assert(is_subclass_of($class, PanelModel::class));

        return new $class;
    }

    public function connection(string $id = 'a'): PostgresConnection
    {
        $connection = $this->capsule->getConnection('social_'.$id);
        assert($connection instanceof PostgresConnection);

        return $connection;
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        foreach (['a', 'b'] as $id) {
            $this->capsule->getDatabaseManager()->disconnect('social_'.$id);
            $this->pg->owner->exec('DROP SCHEMA social_'.$id.' CASCADE');
        }
        $this->pg->owner->exec('DROP TABLE public.p41_host');
        if ($this->previousDb !== null) {
            DB::swap($this->previousDb);
        } else {
            DB::clearResolvedInstance('db');
        }
    }
}
