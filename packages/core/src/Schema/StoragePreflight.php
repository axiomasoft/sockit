<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Illuminate\Database\Connection;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Internal\StorageDeployment;
use SocKit\Schema\Internal\StorageIdentityResolver;
use SocKit\Schema\Internal\StoragePreflight as PhysicalPreflight;

/** Persistence tooling SPI for explicit commands and data-only configuration validation.
 * No ORM models, internal DTOs or runtime discovery at application boot. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class StoragePreflight
{
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
