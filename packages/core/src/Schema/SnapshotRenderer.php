<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Illuminate\Database\Connection;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\Internal\SnapshotDdl;
use SocKit\Schema\Internal\StorageDeployment;
use SocKit\Schema\Internal\StoragePreflight;

/** Explicit initial-install tooling. Returns consumer-owned PHP; never installs or auto-loads DDL. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SnapshotRenderer
{
    public function render(PanelSchema $schema, Connection $connection, mixed $deployment): string
    {
        SchemaValues::require(valid: $connection->getName() === $schema->storage->connection, key: 'connection', rule: 'SNAPSHOT_CONNECTION_MISMATCH');
        $preflight = new StoragePreflight;
        $physical = $preflight->inspect(schema: $schema, connection: $connection, deployment: StorageDeployment::fromArray($deployment));
        $preflight->assertVacant(connection: $connection, schema: $physical);
        $draft = $schema->jsonSerialize();
        $snapshot = SchemaValues::json(['format_version' => 1, 'mode' => 'initial_snapshot', 'connection' => $schema->storage->connection,
            'schema' => $draft, 'physical' => $physical->toArray(), 'endpoints' => $physical->location->deployment->endpoints(),
            'relations' => $physical->relations, 'program' => new SnapshotDdl()->compile(draft: $draft, physical: $physical)]);
        $stub = file_get_contents(dirname(__DIR__, 2).'/resources/stubs/schema/snapshot.php.stub');
        SchemaValues::require(valid: is_string($stub), key: 'stub', rule: 'SNAPSHOT_STUB_UNAVAILABLE');

        return strtr($stub, ['{{SNAPSHOT}}' => base64_encode($snapshot), '{{DIGEST}}' => hash('sha256', $snapshot), '{{CONNECTION}}' => var_export($schema->storage->connection, true)]);
    }
}
