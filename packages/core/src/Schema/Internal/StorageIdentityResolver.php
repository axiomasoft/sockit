<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use Illuminate\Database\Connection;
use Packman\Attributes\Internal;
use PDO;
use SocKit\Schema\Exceptions\InvalidSchemaException;

/** Shared by explicit generation/install/doctor callers. Nothing is registered at boot. */
#[Internal]
final class StorageIdentityResolver
{
    /** Pure path: explicit schema and trusted deployment are mandatory. No PDO/DNS access.
     * @param array<string, mixed> $connection */
    public function declared(array $connection, StorageDeployment $deployment, ?string $schema): StorageLocation
    {
        $this->assertConnection(connection: $connection, deployment: $deployment);
        SchemaValues::require(valid: $schema !== null && SchemaValues::identifier($schema), key: 'schema', rule: 'STORAGE_SCHEMA_UNRESOLVED');
        $database = $connection['database'];
        assert(is_string($database));

        return new StorageLocation(deployment: $deployment, database: $database, schema: $schema);
    }

    /** Explicit read-only command path. Freeze current_schema() when no schema was declared.
     * No DDL, search_path changes, runtime default connection or config mutation. */
    public function discover(Connection $connection, StorageDeployment $deployment, ?string $schema): StorageLocation
    {
        SchemaValues::require(valid: $connection->getRawReadPdo() === null, key: 'connection', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');
        $config = SchemaValues::map($connection->getConfig());
        $this->assertConnection(connection: $config, deployment: $deployment);
        SchemaValues::require(valid: $schema === null || SchemaValues::identifier($schema), key: 'schema', rule: 'STORAGE_IDENTIFIER_INVALID');
        try {
            // Write PDO intentionally avoids a read replica as physical ownership authority.
            $pdo = $connection->getPdo();
            $query = $pdo->query("SELECT current_database() AS db, current_schema() AS schema, host(inet_server_addr()) AS address, inet_server_port() AS port, current_setting('port') AS socket_port, current_setting('unix_socket_directories') AS sockets");
            $row = $query === false ? false : $query->fetch(PDO::FETCH_ASSOC);
            SchemaValues::require(valid: is_array($row) && ($row['db'] ?? null) === $config['database'], key: 'connection', rule: 'STORAGE_LIVE_DISAGREEMENT');
            if (($row['address'] ?? null) === null) {
                $host = $config['host'];
                $port = $config['port'] ?? 5432;
                SchemaValues::require(valid: is_string($host) && str_starts_with($host, '/') && is_string($row['sockets']) && (is_int($port) || is_string($port)) && (is_int($row['socket_port']) || is_string($row['socket_port'])) && (string) $port === (string) $row['socket_port'], key: 'endpoint', rule: 'STORAGE_LIVE_DISAGREEMENT');
                $sockets = array_map(fn (string $directory): string => StorageEndpoint::normalize(host: trim($directory), port: $port), explode(',', $row['sockets']));
                SchemaValues::require(valid: in_array(StorageEndpoint::normalize(host: $host, port: $port), $sockets, true), key: 'endpoint', rule: 'STORAGE_LIVE_DISAGREEMENT');
            } else {
                $observed = StorageEndpoint::normalize(host: $row['address'], port: $row['port']);
                SchemaValues::require(valid: $deployment->admits($observed), key: 'endpoint', rule: 'STORAGE_LIVE_DISAGREEMENT');
            }
            $resolved = $schema ?? $row['schema'];
            SchemaValues::require(valid: is_string($resolved) && SchemaValues::identifier($resolved), key: 'schema', rule: 'STORAGE_SCHEMA_UNRESOLVED');
            // An explicit schema need not be first in search_path, but must actually exist.
            $statement = $pdo->prepare('SELECT 1 FROM pg_namespace WHERE nspname = ?');
            SchemaValues::require(valid: $statement !== false, key: 'schema', rule: 'STORAGE_SCHEMA_UNRESOLVED');
            $statement->execute([$resolved]);
            SchemaValues::require(valid: $statement->fetchColumn() !== false, key: 'schema', rule: 'STORAGE_SCHEMA_UNRESOLVED');
        } catch (InvalidSchemaException $error) {
            throw $error;
        } catch (\Throwable) {
            // Never chain PDO/DSN errors: they may contain credentials, hosts or SQL.
            throw new InvalidSchemaException(message: 'Storage discovery failed', detail: ['key' => 'connection', 'rule' => 'STORAGE_DISCOVERY_FAILED']);
        }

        return $this->declared(connection: $config, deployment: $deployment, schema: $resolved);
    }

    /** @param array<string, mixed> $connection */
    private function assertConnection(array $connection, StorageDeployment $deployment): void
    {
        SchemaValues::require(valid: ($connection['driver'] ?? null) === 'pgsql', key: 'driver', rule: 'STORAGE_DRIVER_UNSUPPORTED');
        // URL/DSN, read/write routing and multi-host failover need a resolved single write endpoint.
        foreach (['url', 'dsn', 'read', 'write'] as $key) {
            SchemaValues::require(valid: ! isset($connection[$key]) || $connection[$key] === '' || $connection[$key] === [], key: 'connection', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');
        }
        $database = $connection['database'] ?? null;
        SchemaValues::require(valid: is_string($database) && SchemaValues::identifier($database), key: 'database', rule: 'STORAGE_IDENTIFIER_INVALID');
        $endpoint = StorageEndpoint::normalize(host: $connection['host'] ?? null, port: $connection['port'] ?? 5432);
        SchemaValues::require(valid: $deployment->admits($endpoint), key: 'endpoint', rule: 'STORAGE_DEPLOYMENT_DISAGREEMENT');
    }
}
