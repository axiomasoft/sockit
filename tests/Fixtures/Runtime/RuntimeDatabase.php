<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use PDO;
use SocKit\Panels\PanelStorage;
use SocKit\Tests\Integration\Support\PgTarget;

/** Test-only snapshots, never production schema or ambient Laravel database configuration. */
final class RuntimeDatabase
{
    public readonly Capsule $capsule;

    public readonly PDO $owner;

    public readonly PDO $decoy;

    /** @var list<array{connection: string|null, sql: string}> */
    public array $queries = [];

    public function __construct(Application $app)
    {
        $target = PgTarget::fromEnvironment();
        // Ownership/endpoint/version/marker checks precede every fixture DDL.
        $this->owner = $target->connect();
        $this->decoy = $target->connect(decoy: true);
        $decoy = $target->decoyConfiguration();
        $owner = $decoy;
        $owner['database'] = (string) PgTarget::query($this->owner, 'SELECT current_database()')->fetchColumn();
        $this->capsule = new Capsule;
        $events = new Dispatcher(new Container);
        $this->capsule->setEventDispatcher($events);
        $events->listen(events: QueryExecuted::class, listener: function (QueryExecuted $query): void {
            $this->queries[] = ['connection' => $query->connectionName, 'sql' => $query->sql];
        });
        $this->capsule->addConnection(config: [...$owner, 'prefix' => 'driver_'], name: 'owner_a');
        $this->capsule->addConnection(config: [...$owner, 'prefix' => 'other_'], name: 'owner_b');
        $this->capsule->addConnection(config: [...$decoy, 'prefix' => 'driver_'], name: 'decoy');
        $manager = $this->capsule->getDatabaseManager();
        $manager->setDefaultConnection('decoy');
        foreach (['owner_a', 'owner_b', 'decoy'] as $name) {
            $connection = $manager->connection($name);
            $connection->setTransactionManager(new DatabaseTransactionsManager);
            $identity = $connection->selectOne('SELECT current_database() AS db, pg_backend_pid() AS pid');
            if (! $identity instanceof \stdClass || ! isset($identity->db, $identity->pid) || ! is_string($identity->db) || ! is_int($identity->pid)) {
                throw new \RuntimeException('Invalid Laravel PDO backend identity');
            }
            fwrite(STDERR, 'P2.2 Laravel connection='.$name.' database='.$identity->db.' backend_pid='.$identity->pid.PHP_EOL);
            expect($identity->db)->toBe($name === 'decoy' ? $decoy['database'] : $owner['database']);
        }
        $app->instance(abstract: ConnectionResolverInterface::class, instance: $manager);
        $this->queries = [];
    }

    public function install(): void
    {
        foreach ([[$this->owner, 'panel_a', 'driver_a_'], [$this->owner, 'panel_b', 'other_b_'], [$this->decoy, 'panel_a', 'driver_a_']] as [$pdo, $schema, $prefix]) {
            $pdo->exec('CREATE SCHEMA '.$schema);
            foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
                $pdo->exec('CREATE TABLE '.$schema.'.'.$prefix.$entity.' (id text PRIMARY KEY, payload text NOT NULL)');
            }
        }
        $this->decoy->exec("INSERT INTO panel_a.driver_a_receipts VALUES ('42', 'decoy-sentinel')");
        $this->owner->exec("INSERT INTO panel_b.other_b_receipts VALUES ('42', 'b-sentinel')");
        $this->queries = [];
    }

    public function cleanup(): void
    {
        $this->owner->exec('DROP SCHEMA IF EXISTS panel_a CASCADE');
        $this->owner->exec('DROP SCHEMA IF EXISTS panel_b CASCADE');
        $this->decoy->exec('DROP SCHEMA IF EXISTS panel_a CASCADE');
        foreach (['owner_a', 'owner_b', 'decoy'] as $name) {
            $this->capsule->getDatabaseManager()->disconnect($name);
        }
    }
}
