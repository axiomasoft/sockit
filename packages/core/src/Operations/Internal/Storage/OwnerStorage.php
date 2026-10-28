<?php

declare(strict_types=1);

namespace SocKit\Operations\Internal\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Operations\OperationHandles;
use SocKit\Panels\PanelStorage;
use SocKit\Support\Data\CommandNamespace;

/** Runtime routing only: no schema discovery, DDL, default connection or global configuration mutation. */
final readonly class OwnerStorage
{
    public function __construct(private ConnectionResolverInterface $connections, private OperationHandles $handles) {}

    public function forHandle(OperationHandle $handle, ?CommandNamespace $namespace = null): OwnerConnection
    {
        $this->handles->assertCurrent(handle: $handle, namespace: $namespace);
        $storage = $handle->panel->storage;
        try {
            $connection = $this->connections->connection($storage->connection);
        } catch (\InvalidArgumentException $error) {
            throw new InvalidConfigurationException(message: 'Panel connection is unavailable', detail: ['panel' => $handle->surface->panel->value, 'key' => 'connection'], previous: $error);
        }
        if (! $connection instanceof Connection) {
            throw new InvalidConfigurationException(message: 'Unsupported owner connection implementation', detail: ['panel' => $handle->surface->panel->value, 'key' => 'connection']);
        }
        $prefix = $connection->getTablePrefix();
        $tables = [];
        foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
            $table = $storage->tables[$entity];
            if (! self::identifier($prefix.$table) || ($prefix !== '' && ! self::identifier($prefix)) || ($storage->schema !== null && ! self::identifier($storage->schema))) {
                throw new InvalidConfigurationException(message: 'Invalid final SQL identifier', detail: ['panel' => $handle->surface->panel->value, 'key' => 'tables.'.$entity]);
            }
            // Laravel's grammar applies the driver prefix exactly once to the table segment, never to the schema.
            $tables[$entity] = ($storage->schema === null ? '' : $storage->schema.'.').$table;
        }

        return new OwnerConnection(connection: $connection, handles: $this->handles, handle: $handle, namespace: $namespace, tables: $tables, prefix: $prefix);
    }

    private static function identifier(string $value): bool
    {
        return strlen($value) <= 63 && preg_match('/^[a-z_][a-z0-9_]*$/D', $value) === 1;
    }
}
