<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Illuminate\Database\Connection;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Internal\StorageDeployment;
use SocKit\Schema\Internal\StorageIdentityResolver;
use SocKit\Schema\Internal\StoragePreflight as PhysicalPreflight;

/** Persistence tooling SPI for explicit commands and data-only configuration validation.
 * No ORM models, internal DTOs or runtime discovery at application boot. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class StoragePreflight
{
    /** Pure generation preflight: trusted deployments and explicit schemas, never PDO.
     * @param list<PanelSchema> $schemas
     * @param array<string, array<string, mixed>> $connections
     * @return array<string, array<string, string>> Panel ID => final physical tables */
    public static function generation(array $schemas, array $connections, mixed $deployments): array
    {
        self::validateDeployments($deployments);
        assert(is_array($deployments));
        $preflight = new PhysicalPreflight;
        $physical = $tables = [];
        foreach ($schemas as $schema) {
            $name = $schema->storage->connection;
            $config = $connections[$name] ?? [];
            $prefix = $config['prefix'] ?? '';
            if (! is_string($prefix)) {
                throw new Exceptions\InvalidSchemaException(message: 'Invalid driver prefix', detail: ['key' => 'prefix', 'rule' => 'STORAGE_IDENTIFIER_INVALID']);
            }
            $location = new StorageIdentityResolver()->declared(connection: $config, deployment: StorageDeployment::fromArray($deployments[$name] ?? null), schema: $schema->storage->schema);
            $resolved = $preflight->compile(schema: $schema, location: $location, driverPrefix: $prefix);
            $physical[] = $resolved;
            $tables[$schema->panel] = $preflight->tables(storage: $schema->storage, location: $location, driverPrefix: $prefix);
        }
        $preflight->assertDisjoint($physical);

        return $tables;
    }

    public static function validateDeployments(mixed $deployments): void
    {
        StorageDeployment::validateRegistry($deployments);
    }

    /** Explicit read-only discovery; returns final names without credentials or connection state.
     * @return array{driver:string, cluster:string, endpoint:string, database:string, schema:string, tables:array<string, string>} */
    public static function inspect(PanelStorage $storage, Connection $connection, mixed $deployment): array
    {
        $location = new StorageIdentityResolver()->discover(connection: $connection, deployment: StorageDeployment::fromArray($deployment), schema: $storage->schema);
        $tables = new PhysicalPreflight()->tables(storage: $storage, location: $location, driverPrefix: $connection->getTablePrefix());

        return [...$location->toArray(), 'tables' => $tables];
    }
}
