<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Internal\Generation\ExclusiveWriter;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\Internal\MigrationHistory;
use SocKit\Schema\Internal\MigrationPlanner;
use SocKit\Schema\Internal\SchemaValues;

/** Explicit console tooling. Reads the owner ledger/catalog; DDL is left to Laravel migrate. */
#[Spi(kind: SpiKind::Call)]
final readonly class PanelMigrations
{
    public function __construct(private Modules $modules, private PanelDefinitions $panels, private Repository $config, private ConnectionResolverInterface $connections) {}

    /** @return array<string, mixed> */
    public function run(string $root, string $panel, bool $upgrade = false, bool $dryRun = false): array
    {
        $definition = $this->panels->get(new PanelId($panel));
        $schema = new PanelSchemaCompiler()->compile(panel: $definition, components: $this->modules->schemaComponents());
        // Include frozen ownership of disabled/unregistered panels in the collision preflight.
        $schemas = [];
        foreach ($this->panels->all() as $other) {
            $draft = new PanelSchemaCompiler()->compile(panel: $other, components: $this->modules->schemaComponents());
            $history = new MigrationHistory()->read(root: $root, panel: $draft->panel);
            $schemas[$draft->panel] = $history === [] ? $draft : MigrationHistory::union(previous: SchemaValues::map($history[array_key_last($history)]['schema']), desired: $draft);
        }
        foreach (glob($root.'/database/sockit/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $id = new PanelId(basename($directory));
            if (! isset($schemas[$id->value])) {
                $history = new MigrationHistory()->read(root: $root, panel: $id->value);
                GenerationFailure::require(valid: $history !== [], rule: 'MIGRATION_HISTORY_MISSING');
                $schemas[$id->value] = MigrationHistory::schema(SchemaValues::map($history[array_key_last($history)]['schema']));
            }
        }
        $configured = SchemaValues::map($this->config->get('database.connections', []));
        StoragePreflight::generation(schemas: array_values($schemas), connections: array_map(SchemaValues::map(...), $configured), deployments: $this->config->get('sockit.storage.deployments', []));
        $connection = $this->connections->connection($schema->storage->connection);
        GenerationFailure::require(valid: $connection instanceof Connection, rule: 'MIGRATION_CONNECTION_UNSUPPORTED');
        $repository = new DatabaseMigrationRepository(resolver: $this->connections, table: SchemaValues::text($this->config->get('database.migrations.table', 'migrations')));
        $repository->setSource($schema->storage->connection);
        $ran = $repository->repositoryExists() ? array_values(array_map(SchemaValues::text(...), $repository->getRan())) : [];
        $deployments = SchemaValues::map($this->config->get('sockit.storage.deployments', []));
        $planner = new MigrationPlanner;
        $plan = $planner->plan(root: $root, desired: $schema, connection: $connection, deployment: $deployments[$schema->storage->connection] ?? null, ran: $ran, upgrade: $upgrade);
        if (! $dryRun && $plan->sources !== []) {
            $baseline = new MigrationHistory()->read(root: $root, panel: $panel);
            $checked = false;
            // Called under the existing root lock, before the first destination is installed.
            $writer = new ExclusiveWriter(static function (string $phase, string $path) use ($root, $panel, $baseline, $repository, $ran, &$checked): void {
                if ($phase === 'staged' && ! $checked) {
                    GenerationFailure::require(valid: new MigrationHistory()->read(root: $root, panel: $panel) === $baseline, rule: 'MIGRATION_HISTORY_CHANGED_DURING_WRITE');
                    $current = $repository->repositoryExists() ? array_values(array_map(SchemaValues::text(...), $repository->getRan())) : [];
                    GenerationFailure::require(valid: $current === $ran, rule: 'MIGRATION_LEDGER_CHANGED_DURING_WRITE');
                    $checked = true;
                }
            });
            $writer->install($plan);
        }

        return [...$plan->manifest, 'status' => $plan->sources === [] ? 'no_op' : ($dryRun ? 'dry_run' : 'generated')];
    }
}
