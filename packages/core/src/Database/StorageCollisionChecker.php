<?php

declare(strict_types=1);

namespace SocKit\Database;

use Packman\Attributes\Api;
use Packman\Schema\Data\Compiler\ConstraintNames;
use SocKit\Panels\Data\PanelDefinition;
use SocKit\Panels\Exceptions\PanelException;

/**
 * Detects physical storage collisions across compiled panels (including connection aliases pointing to the same database)
 * and enforces PostgreSQL 63-byte identifier bounds without exposing DB credentials.
 */
#[Api(since: '1.0')]
final readonly class StorageCollisionChecker
{
    /** @var list<string> */
    private const array CORE_LOGICAL_TABLES = [
        'surface_instances',
        'command_receipts',
        'outbox_events',
        'resource_tombstones',
    ];

    /**
     * @param  array<string, PanelDefinition>  $panels
     * @param  array<string, array<string, mixed>>  $databaseConnections  sanitized Laravel database.connections config map
     * @param  array<string, list<string>>  $moduleTables  module id => logical table names
     */
    public function assertNoCollisions(
        array $panels,
        array $databaseConnections,
        string $defaultConnection,
        array $moduleTables = [],
    ): void {
        $claimed = [];

        foreach ($panels as $panel) {
            $connectionName = $panel->storage->connection !== '' ? $panel->storage->connection : $defaultConnection;
            $connectionConfig = $databaseConnections[$connectionName] ?? [];
            $endpoint = $this->physicalEndpoint($connectionName, $connectionConfig, $panel->storage->schema);

            $logicalTables = self::CORE_LOGICAL_TABLES;
            foreach ($panel->modules as $moduleId) {
                foreach ($moduleTables[$moduleId] ?? [] as $table) {
                    $logicalTables[] = $table;
                }
            }

            foreach (array_values(array_unique($logicalTables)) as $logical) {
                $physical = $panel->storage->resolveTable($logical);

                if (strlen(ConstraintNames::primary($physical)) > ConstraintNames::MAX_IDENTIFIER) {
                    throw PanelException::invalidConfig(
                        'PANEL_TABLE_INVALID',
                        "Primary constraint name for [{$physical}] on panel [{$panel->id->value}] exceeds 63 bytes.",
                        ['panel' => $panel->id->value, 'table' => $physical],
                    );
                }

                $claimKey = $endpoint.'::'.strtolower($physical);

                if (isset($claimed[$claimKey]) && $claimed[$claimKey] !== $panel->id->value) {
                    throw PanelException::invalidConfig(
                        'PANEL_STORAGE_COLLISION',
                        "Panel [{$panel->id->value}] collides with panel [{$claimed[$claimKey]}] on physical table [{$physical}].",
                        ['panel' => $panel->id->value, 'other_panel' => $claimed[$claimKey], 'table' => $physical],
                    );
                }

                $claimed[$claimKey] = $panel->id->value;
            }
        }
    }

    /**
     * Computes a secret-free physical storage fingerprint so alias connections to the same server/database/schema are caught.
     *
     * @param  array<string, mixed>  $config
     */
    public function physicalEndpoint(string $connectionName, array $config, ?string $panelSchema = null): string
    {
        if ($config === []) {
            return 'connection:'.$connectionName.'|schema:'.($panelSchema ?? 'default');
        }

        $driver = is_string($config['driver'] ?? null) ? strtolower($config['driver']) : 'unknown';

        if ($driver === 'sqlite') {
            $db = is_string($config['database'] ?? null) ? $config['database'] : ':memory:';
            $resolvedPath = $db === ':memory:' ? ':memory:' : realpath($db);

            return $db === ':memory:'
                ? 'sqlite:memory:'.$connectionName
                : 'sqlite:file:'.($resolvedPath !== false ? $resolvedPath : $db);
        }

        $socket = is_string($config['unix_socket'] ?? null) ? trim($config['unix_socket']) : '';
        $readConfig = is_array($config['read'] ?? null) ? $config['read'] : [];
        $writeConfig = is_array($config['write'] ?? null) ? $config['write'] : [];
        $rawHost = $config['host'] ?? ($readConfig['host'] ?? ($writeConfig['host'] ?? '127.0.0.1'));
        $host = is_array($rawHost)
            ? (is_string($rawHost[0] ?? null) ? $rawHost[0] : '127.0.0.1')
            : (is_string($rawHost) ? $rawHost : '127.0.0.1');
        $port = $config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        $portInt = is_int($port) || is_string($port) ? (int) $port : 5432;
        $database = is_string($config['database'] ?? null) ? $config['database'] : '';
        $schema = $panelSchema ?? (is_string($config['search_path'] ?? null) ? $config['search_path'] : 'public');
        $prefix = is_string($config['prefix'] ?? null) ? $config['prefix'] : '';

        $endpoint = $socket !== ''
            ? 'unix:'.$socket
            : 'tcp:'.strtolower($host).':'.$portInt;

        return sprintf('%s|%s|db:%s|schema:%s|conn_prefix:%s', $driver, $endpoint, $database, $schema, $prefix);
    }
}
