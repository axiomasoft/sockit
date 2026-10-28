<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use Illuminate\Database\Connection;
use PDO;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\PanelSchema;

final class StoragePreflight
{
    /** Explicit command entrypoint: bind prefix and live schema to the same write connection. */
    public function inspect(PanelSchema $schema, Connection $connection, StorageDeployment $deployment): PhysicalSchema
    {
        $location = new StorageIdentityResolver()->discover(connection: $connection, deployment: $deployment, schema: $schema->storage->schema);

        return $this->compile(schema: $schema, location: $location, driverPrefix: $connection->getTablePrefix());
    }

    /** Prefix is applied exactly once to declared tables, never to schema/columns/constraints. */
    public function compile(PanelSchema $schema, StorageLocation $location, string $driverPrefix): PhysicalSchema
    {
        $draft = $schema->jsonSerialize();
        $entities = SchemaValues::map($draft['entities']);
        SchemaValues::require(valid: array_keys($entities) === array_keys($schema->storage->tables), key: 'entities', rule: 'STORAGE_MAP_INCOMPLETE');
        $tables = $this->tables(storage: $schema->storage, location: $location, driverPrefix: $driverPrefix);
        $relations = $physical = [];
        foreach ($tables as $final) {
            self::claim(relations: $relations, name: $final);
        }
        foreach ($entities as $entity => $value) {
            $spec = SchemaValues::map($value);
            $columns = SchemaValues::map($spec['columns'] ?? null);
            foreach (array_keys($columns) as $column) {
                self::identifier($column);
            }
            $spec['table'] = $tables[$entity];
            $names = [];
            foreach (['primary' => 'pk', 'indexes' => 'idx', 'foreign_keys' => 'fk', 'checks' => 'ck'] as $section => $kind) {
                $keys = $section === 'primary' ? ['primary' => SchemaValues::map($spec[$section] ?? null)] : SchemaValues::map($spec[$section] ?? null);
                foreach ($keys as $logical => $definition) {
                    $key = SchemaValues::map($definition);
                    // No lossy truncation: the digest uses full qualified physical identity and purpose.
                    $name = 'sk_'.$kind.'_'.substr(hash('sha256', SchemaValues::json([$schema->panel, $location->tableKey($tables[$entity]), $kind, $logical])), 0, 32);
                    self::identifier($name);
                    SchemaValues::require(valid: ! isset($names[$name]), key: 'constraints', rule: 'STORAGE_NAME_COLLISION');
                    $names[$name] = true;
                    if ($kind === 'idx' || $kind === 'pk') {
                        self::claim(relations: $relations, name: $name);
                    }
                    $key['name'] = $name;
                    if ($kind === 'fk') {
                        $target = $key['entity'] ?? null;
                        SchemaValues::require(valid: is_string($target) && isset($tables[$target]), key: 'foreign_keys', rule: 'STORAGE_MAP_INCOMPLETE');
                        $key['table'] = $tables[$target];
                        $key['schema'] = $location->schema;
                    }
                    $keys[$logical] = $key;
                }
                $spec[$section] = $section === 'primary' ? $keys['primary'] : $keys;
            }
            $physical[$entity] = $spec;
        }

        return new PhysicalSchema(location: $location, descriptor: ['format_version' => 1, 'mode' => 'physical_preflight', 'panel' => $schema->panel,
            'source_digest' => $schema->digest, 'location' => $location->toArray(), 'driver_prefix' => $driverPrefix, 'tables' => $tables, 'entities' => $physical], relations: array_keys($relations));
    }

    /** Final names shared with doctor even before a production DDL snapshot is installed.
     * @return array<string, string> */
    public function tables(PanelStorage $storage, StorageLocation $location, string $driverPrefix): array
    {
        SchemaValues::require(valid: $driverPrefix === '' || SchemaValues::identifier($driverPrefix), key: 'prefix', rule: 'STORAGE_IDENTIFIER_INVALID');
        SchemaValues::require(valid: $storage->schema === null || $storage->schema === $location->schema, key: 'schema', rule: 'STORAGE_LIVE_DISAGREEMENT');
        $tables = [];
        foreach ($storage->tables as $entity => $table) {
            $final = $driverPrefix.$table;
            self::identifier($final);
            $tables[$entity] = $final;
        }

        return $tables;
    }

    /** Generation/installation must submit the complete proposed ownership set before writing.
     * @param list<PhysicalSchema> $schemas */
    public function assertDisjoint(array $schemas): void
    {
        StorageDeployment::assertConsistent(array_map(fn (PhysicalSchema $schema): StorageDeployment => $schema->location->deployment, $schemas));
        $claimed = [];
        foreach ($schemas as $schema) {
            foreach ($schema->relations as $relation) {
                $key = $schema->location->tableKey($relation);
                SchemaValues::require(valid: ! isset($claimed[$key]), key: 'ownership', rule: 'STORAGE_OWNERSHIP_COLLISION');
                $claimed[$key] = true;
            }
        }
    }

    /** Catalog-only initial-install guard. Applied owned snapshots are handled by upgrade callers. */
    public function assertVacant(Connection $connection, PhysicalSchema $schema): void
    {
        $observed = new StorageIdentityResolver()->discover(connection: $connection, deployment: $schema->location->deployment, schema: $schema->location->schema);
        SchemaValues::require(valid: $observed->key() === $schema->location->key(), key: 'connection', rule: 'STORAGE_LIVE_DISAGREEMENT');
        if ($schema->relations === []) {
            return;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($schema->relations), '?'));
            $query = $connection->getPdo()->prepare('SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname IN ('.$placeholders.')');
            SchemaValues::require(valid: $query !== false, key: 'catalog', rule: 'STORAGE_DISCOVERY_FAILED');
            $query->execute([$schema->location->schema, ...$schema->relations]);
            SchemaValues::require(valid: $query->fetch(PDO::FETCH_COLUMN) === false, key: 'ownership', rule: 'STORAGE_OWNERSHIP_COLLISION');
        } catch (InvalidSchemaException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new InvalidSchemaException(message: 'Storage catalog inspection failed', detail: ['key' => 'catalog', 'rule' => 'STORAGE_DISCOVERY_FAILED']);
        }
    }

    private static function identifier(string $value): void
    {
        SchemaValues::require(valid: SchemaValues::identifier($value), key: 'identifier', rule: 'STORAGE_IDENTIFIER_INVALID');
    }

    /** @param array<string, true> $relations */
    private static function claim(array &$relations, string $name): void
    {
        SchemaValues::require(valid: ! isset($relations[$name]), key: 'relations', rule: 'STORAGE_NAME_COLLISION');
        $relations[$name] = true;
    }
}
