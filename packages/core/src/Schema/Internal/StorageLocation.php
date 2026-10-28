<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

/** Resolved physical namespace. Contains no connection credentials or search_path. */
final readonly class StorageLocation
{
    public function __construct(public StorageDeployment $deployment, public string $database, public string $schema)
    {
        SchemaValues::require(valid: SchemaValues::identifier($database) && SchemaValues::identifier($schema), key: 'location', rule: 'STORAGE_IDENTIFIER_INVALID');
    }

    /** @return array{driver:string, cluster:string, endpoint:string, database:string, schema:string} */
    public function toArray(): array
    {
        return ['driver' => 'pgsql', 'cluster' => $this->deployment->cluster, 'endpoint' => $this->deployment->endpoint, 'database' => $this->database, 'schema' => $this->schema];
    }

    public function key(): string
    {
        return hash('sha256', SchemaValues::json($this->toArray()));
    }

    public function tableKey(string $table): string
    {
        SchemaValues::require(valid: SchemaValues::identifier($table), key: 'table', rule: 'STORAGE_IDENTIFIER_INVALID');

        return hash('sha256', SchemaValues::json([$this->toArray(), $table]));
    }
}
