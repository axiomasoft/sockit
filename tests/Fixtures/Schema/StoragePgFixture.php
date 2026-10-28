<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Illuminate\Database\PostgresConnection;
use PDO;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\Internal\StorageDeployment;
use SocKit\Tests\Integration\Support\PgTarget;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final readonly class StoragePgFixture
{
    public PDO $owner;

    private PDO $alias;

    public PDO $decoy;

    public StorageDeployment $deployment;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $target = PgTarget::fromEnvironment();
        // Container label, exact endpoint, *_test name and database marker BEFORE any mutation.
        $this->owner = $target->connect();
        $this->alias = $target->connect();
        $this->decoy = $target->connect(decoy: true);
        $database = Scenario::text(PgTarget::query($this->owner, 'SELECT current_database()')->fetchColumn());
        $this->config = [...$target->decoyConfiguration(), 'database' => $database];
        $observed = PgTarget::query($this->owner, 'SELECT host(inet_server_addr()) AS host, inet_server_port() AS port')->fetch(PDO::FETCH_ASSOC);
        $this->deployment = StorageDeployment::fromArray(['cluster' => 'owned_pg', 'endpoint' => ['host' => $this->config['host'], 'port' => $this->config['port']], 'aliases' => [$observed]]);
    }

    /** Independent verified PDO backends, two names, same physical owner database. */
    public function connection(string $name = 'first', string $prefix = 'drv_'): PostgresConnection
    {
        return new PostgresConnection(pdo: $name === 'alias' ? $this->alias : $this->owner, database: Scenario::text($this->config['database']), tablePrefix: $prefix, config: [...$this->config, 'name' => $name]);
    }

    public static function quote(string $identifier): string
    {
        SchemaValues::require(valid: SchemaValues::identifier($identifier), key: 'fixture', rule: 'UNSAFE_FIXTURE_IDENTIFIER');

        return '"'.$identifier.'"';
    }
}
