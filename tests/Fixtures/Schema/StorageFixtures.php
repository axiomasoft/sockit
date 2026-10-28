<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Internal\StorageDeployment;
use SocKit\Schema\PanelSchema;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class StorageFixtures
{
    public static function deployment(string $cluster = 'cluster_a', string $host = 'db.internal', int $port = 5432): StorageDeployment
    {
        return StorageDeployment::fromArray(['cluster' => $cluster, 'endpoint' => ['host' => $host, 'port' => $port],
            'aliases' => [['host' => $cluster === 'cluster_a' ? 'alias.internal' : 'other-alias.internal', 'port' => $port]]]);
    }

    /** @return array<string, mixed> */
    public static function connection(string $host = 'db.internal'): array
    {
        return ['driver' => 'pgsql', 'host' => $host, 'port' => 5432, 'database' => 'sockit_test', 'password' => 'never-in-identity'];
    }

    /** @param array<string, string> $tables */
    public static function schema(string $panel = 'host.articles', string $prefix = 'articles_', array $tables = []): PanelSchema
    {
        $definition = (new Panel)->id($panel)->hostTypes('article')->prefix($prefix)->modules('acme.demo-targets', 'acme.demo-links')->materialize();
        // The compiler resolves component entities; overrides are supplied as draft snapshot data.
        $schema = new PanelSchemaCompiler()->compile(panel: $definition, components: ['acme.demo-targets' => new DemoTargetsV1()->component(), 'acme.demo-links' => new DependentContribution()->component()]);
        if ($tables === []) {
            return $schema;
        }
        $storage = new PanelStorage(connection: $schema->storage->connection, schema: null, prefix: $prefix,
            tables: array_replace($schema->storage->tables, $tables), models: $schema->storage->models);
        $wire = $schema->jsonSerialize();

        return new PanelSchema(panel: $panel, storage: $storage, components: Scenario::object($wire['components']), entities: Scenario::object($wire['entities']), order: array_map(Scenario::text(...), Scenario::entries($wire['dependency_order'])));
    }
}
