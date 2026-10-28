<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use SocKit\Panels\Panel;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\PanelSchema;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Schema\SnapshotRenderer;
use SocKit\Tests\Fixtures\Runtime\EnvelopeScenario;
use SocKit\Tests\Fixtures\Runtime\SurfaceScenario;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

/** Real generated migrations and verified owned PG only. No copied kernel CREATE TABLE fixture. */
final class SnapshotFixture
{
    public readonly StoragePgFixture $pg;

    public readonly Manager $capsule;

    /** @var array<string, LoadedSnapshot> */
    public array $migrations = [];

    /** @var array<string, string> */
    public array $sources = [];

    /** @var array<string, string> */
    public array $paths = [];

    /** @var array<string, PanelSchema> */
    public array $schemas = [];

    private mixed $previousDb;

    public function __construct(bool $explicitSchema = true, int $version = 1)
    {
        $this->pg = new StoragePgFixture;
        $this->capsule = new Manager;
        $this->previousDb = DB::getFacadeRoot();
        foreach (['a', 'b'] as $id) {
            $this->pg->owner->exec('CREATE SCHEMA panel_'.$id);
            $connection = $this->pg->connection(name: 'owner_'.$id, prefix: $id === 'a' ? 'driver_' : 'other_');
            $this->capsule->addConnection(config: [...SchemaValues::map($connection->getConfig()), 'prefix' => $connection->getTablePrefix()], name: 'owner_'.$id);
            $schema = $this->compile(id: $id, version: $version, explicitSchema: $explicitSchema);
            if (! $explicitSchema) {
                $connection->getPdo()->exec('SET search_path TO panel_'.$id.', public');
            }
            $this->schemas[$id] = $schema;
            $this->sources[$id] = new SnapshotRenderer()->render(schema: $schema, connection: $connection, deployment: $this->deployment());
            $this->paths[$id] = self::file($this->sources[$id]);
            $this->migrations[$id] = self::load($this->paths[$id]);
        }
        DB::swap($this->capsule->getDatabaseManager());
        // Host and decoy sentinels are outside the snapshot and intentionally not dependencies.
        $this->pg->owner->exec('CREATE TABLE public.p33_host (value text NOT NULL)');
        $this->pg->owner->exec("INSERT INTO public.p33_host VALUES ('host-sentinel')");
        $this->pg->decoy->exec('CREATE SCHEMA panel_a');
        $this->pg->decoy->exec('CREATE TABLE panel_a.driver_a_receipts (id text PRIMARY KEY, payload text)');
        $this->pg->decoy->exec("INSERT INTO panel_a.driver_a_receipts VALUES ('42','decoy-sentinel')");
        $this->pg->decoy->exec('CREATE TABLE panel_a.driver_a_surface_instances (id text PRIMARY KEY)');
        foreach (['outbox', 'tombstones'] as $entity) {
            $this->pg->decoy->exec('CREATE TABLE panel_a.driver_a_'.$entity.' (id text PRIMARY KEY)');
        }
    }

    public function envelope(): EnvelopeScenario
    {
        foreach ($this->migrations as $migration) {
            $migration->up();
        }
        $runtime = new EnvelopeScenario(install: false);
        // Only the test module/host rows are handwritten; kernel DDL comes from the renderer.
        $pdo = $this->pg->owner;
        $pdo->exec('CREATE TABLE panel_a.driver_envelope_authority (allowed boolean NOT NULL, generation bigint NOT NULL DEFAULT 1)');
        $pdo->exec('INSERT INTO panel_a.driver_envelope_authority (allowed) VALUES (true)');
        $pdo->exec('CREATE TABLE panel_a.driver_envelope_targets (id text PRIMARY KEY, incarnation bigint, generation text, privacy bigint, visible boolean, lifecycle text, held boolean)');
        $pdo->exec("INSERT INTO panel_a.driver_envelope_targets VALUES ('42',1,'1',1,true,'live',false)");
        $pdo->exec('CREATE TABLE panel_a.driver_envelope_state (target text PRIMARY KEY, effects bigint NOT NULL)');
        $pdo->exec("INSERT INTO panel_a.driver_envelope_state VALUES ('42',0)");
        $pdo->exec('CREATE TABLE panel_a.driver_envelope_revisions (target text PRIMARY KEY, resource text, revision bigint)');
        SurfaceScenario::create($runtime->base->owner());
        SurfaceScenario::create($runtime->base->owner('acme.b'));
        $pdo->exec("INSERT INTO panel_a.driver_envelope_revisions SELECT '42',id,1 FROM panel_a.driver_a_surface_instances");

        return $runtime;
    }

    public function compile(string $id = 'a', int $version = 1, bool $selected = true, bool $explicitSchema = true): PanelSchema
    {
        $panel = (new Panel)->id('acme.'.$id)->hostTypes('article')->connection('owner_'.$id)->prefix($id.'_')->modules(...($selected ? ['acme.demo-targets', 'acme.demo-links'] : []));
        if ($explicitSchema) {
            $panel->schema('panel_'.$id);
        }

        return new PanelSchemaCompiler()->compile(panel: $panel->materialize(), components: $selected ? [
            'acme.demo-targets' => ($version === 1 ? new DemoTargetsV1 : new DemoTargetsV2)->component(),
            'acme.demo-links' => new DependentContribution()->component(),
        ] : []);
    }

    /** @return array<string, mixed> */
    public function deployment(): array
    {
        $connection = $this->pg->connection();
        $config = SchemaValues::map($connection->getConfig());
        $observed = PgTarget::query($connection->getPdo(), 'SELECT host(inet_server_addr()) AS host, inet_server_port() AS port')->fetch(PDO::FETCH_ASSOC);

        return ['cluster' => 'owned_pg', 'endpoint' => ['host' => $config['host'], 'port' => $config['port']], 'aliases' => [$observed]];
    }

    public function connection(string $id = 'a'): PostgresConnection
    {
        $connection = $this->capsule->getConnection('owner_'.$id);
        assert($connection instanceof PostgresConnection);

        return $connection;
    }

    /** @return array<string, mixed> */
    public function descriptor(string $id = 'a'): array
    {
        return self::decode($this->migrations[$id]);
    }

    /** @return array<string, mixed> */
    public static function decode(LoadedSnapshot $migration): array
    {
        $encoded = $migration->constant('SNAPSHOT');
        $json = base64_decode($encoded, true);
        assert(is_string($json));

        return Scenario::object(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public static function file(string $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sockit-p33-');
        if ($path === false || file_put_contents($path, $source) === false) {
            throw new \RuntimeException('Cannot stage consumer migration');
        }

        return $path;
    }

    public static function load(string $path): LoadedSnapshot
    {
        return new LoadedSnapshot($path);
    }

    /** @return list<array<string, mixed>> */
    public function catalog(string $id = 'a'): array
    {
        $statement = $this->pg->owner->prepare('SELECT c.relname,c.oid::text,obj_description(c.oid) AS receipt FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? ORDER BY c.relname');
        $statement->execute(['panel_'.$id]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(Scenario::object(...), $rows));
    }

    public function untouched(): void
    {
        expect(PgTarget::query($this->pg->owner, 'SELECT value FROM public.p33_host')->fetchColumn())->toBe('host-sentinel')
            ->and(PgTarget::query($this->pg->decoy, 'SELECT payload FROM panel_a.driver_a_receipts')->fetchColumn())->toBe('decoy-sentinel');
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            unlink($path);
        }
        foreach (['owner_a', 'owner_b'] as $name) {
            $this->capsule->getDatabaseManager()->disconnect($name);
        }
        // Test harness cleanup is limited to schemas it created in the verified private run.
        $this->pg->owner->exec('DROP SCHEMA panel_a CASCADE');
        $this->pg->owner->exec('DROP SCHEMA panel_b CASCADE');
        $this->pg->owner->exec('DROP TABLE public.p33_host');
        $this->pg->decoy->exec('DROP SCHEMA panel_a CASCADE');
        if ($this->previousDb !== null) {
            DB::swap($this->previousDb);
        } else {
            DB::clearResolvedInstance('db');
        }
    }
}
