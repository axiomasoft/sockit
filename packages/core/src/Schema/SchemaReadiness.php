<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Modules\Modules;
use SocKit\Panels\PanelDefinition;
use SocKit\Panels\PanelDefinitions;
use SocKit\Schema\Exceptions\InvalidSchemaException;
use SocKit\Schema\Internal\MigrationHistory;
use SocKit\Schema\Internal\ReadinessCatalog;
use SocKit\Schema\Internal\SchemaValues;
use SocKit\Schema\Internal\StorageDeployment;
use SocKit\Schema\Internal\StoragePreflight as PhysicalPreflight;

/** Explicit diagnostic only. Never emits files, repairs history or runs migration DDL. */
#[Spi(kind: SpiKind::Call)]
final readonly class SchemaReadiness
{
    public function __construct(private Modules $modules, private PanelDefinitions $panels, private Repository $config, private ConnectionResolverInterface $connections) {}

    /** @param (callable(): list<string>)|null $runtimeProbe
     * @return array{status:string, panel:string, provider:string, generated:int, applied:int, components:array<string, array{version:mixed, status:string}>, problems:list<array{code:string, step:string, path?:string, action:string, ...}>, deliveries:array<string, mixed>, generated_digest?:mixed, installed_digest?:string, desired_digest?:string} */
    public function inspect(string $root, PanelDefinition $panel, ?callable $runtimeProbe = null): array
    {
        $step = 'compile';
        $path = 'database/sockit/'.$panel->id->value;
        $report = ['status' => 'unready', 'panel' => $panel->id->value, 'provider' => $panel->provider,
            'generated' => 0, 'applied' => 0, 'components' => [], 'problems' => [], 'deliveries' => []];
        $connection = null;
        $started = false;
        try {
            $compiler = new PanelSchemaCompiler;
            $desired = $compiler->compile(panel: $panel, components: $this->modules->schemaComponents());
            $step = 'history';
            $history = new MigrationHistory;
            $chain = $history->read(root: $root, panel: $desired->panel);
            GenerationFailure::require(valid: $chain !== [], rule: 'SCHEMA_HISTORY_MISSING');
            $report['generated'] = count($chain);
            $head = $chain[array_key_last($chain)];
            $schema = MigrationHistory::union(previous: SchemaValues::map($head['schema']), desired: $desired);
            $report['generated_digest'] = SchemaValues::map($head['schema'])['digest'];
            $report['desired_digest'] = $schema->digest;
            foreach (SchemaValues::map(SchemaValues::map($head['schema'])['components']) as $id => $value) {
                $report['components'][$id] = ['version' => SchemaValues::map($value)['version'],
                    'status' => isset(SchemaValues::map($desired->jsonSerialize()['components'])[$id]) ? 'selected' : 'preserved_owned'];
            }
            $step = 'identity';
            // All registered and retained frozen owners participate in alias/collision checks.
            $schemas = [];
            foreach ($this->panels->all() as $definition) {
                $draft = $compiler->compile(panel: $definition, components: $this->modules->schemaComponents());
                $records = $history->read(root: $root, panel: $draft->panel);
                $schemas[$draft->panel] = $records === [] ? $draft : MigrationHistory::union(previous: SchemaValues::map($records[array_key_last($records)]['schema']), desired: $draft);
            }
            foreach (glob($root.'/database/sockit/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $id = basename($directory);
                if (! isset($schemas[$id])) {
                    $records = $history->read(root: $root, panel: $id);
                    GenerationFailure::require(valid: $records !== [], rule: 'SCHEMA_HISTORY_MISSING');
                    $schemas[$id] = MigrationHistory::schema(SchemaValues::map($records[array_key_last($records)]['schema']));
                }
            }
            $deployments = SchemaValues::map($this->config->get('sockit.storage.deployments', []));
            StoragePreflight::generation(schemas: array_values($schemas), connections: array_map(SchemaValues::map(...), SchemaValues::map($this->config->get('database.connections', []))), deployments: $deployments);
            $connection = $this->connections->connection($schema->storage->connection);
            GenerationFailure::require(valid: $connection instanceof Connection && $connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction(), rule: 'SCHEMA_DIAGNOSTIC_TRANSACTION_REQUIRED');
            $pdo = $connection->getPdo();
            $pdo->beginTransaction();
            $started = true;
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $physical = new PhysicalPreflight()->inspect(schema: $schema, connection: $connection, deployment: StorageDeployment::fromArray($deployments[$schema->storage->connection] ?? null));
            foreach (['location', 'driver_prefix'] as $key) {
                GenerationFailure::require(valid: SchemaValues::json(SchemaValues::map($head['physical'])[$key]) === SchemaValues::json($physical->toArray()[$key]), rule: 'SCHEMA_PHYSICAL_IDENTITY_CHANGED');
            }
            $step = 'ledger';
            $repository = new DatabaseMigrationRepository(resolver: $this->connections, table: SchemaValues::text($this->config->get('database.migrations.table', 'migrations')));
            $repository->setSource($schema->storage->connection);
            $ran = $repository->repositoryExists() ? array_values(array_map(SchemaValues::text(...), $repository->getRan())) : [];
            $marker = '_sockit_'.substr(hash('sha256', $schema->panel), 0, 16);
            $applied = array_values(array_filter($ran, fn (string $name): bool => str_ends_with($name, $marker)));
            sort($applied);
            $names = array_map(fn (array $record): string => SchemaValues::text($record['migration']), $chain);
            GenerationFailure::require(valid: $applied === array_slice($names, 0, count($applied)), rule: 'MIGRATION_LEDGER_NOT_PREFIX');
            $report['applied'] = count($applied);
            if ($applied !== []) {
                $report['installed_digest'] = SchemaValues::text(SchemaValues::map($chain[count($applied) - 1]['schema'])['digest']);
            }
            GenerationFailure::require(valid: count($applied) === count($chain), rule: 'SCHEMA_GENERATED_PENDING');
            GenerationFailure::require(valid: $schema->digest === SchemaValues::map($head['schema'])['digest'], rule: 'SCHEMA_UPGRADE_REQUIRED');
            $step = 'resolvers';
            foreach ($runtimeProbe === null ? [] : $runtimeProbe() as $code) {
                $report['problems'][] = ['code' => $code, 'step' => $step, 'path' => 'sockit.resolvers',
                    'action' => 'Configure the host resolver implementation and its container dependencies. Constructor queries must support read-only diagnostics.'];
            }
            $step = 'catalog';
            $pdo->exec('SET LOCAL search_path TO pg_catalog');
            $catalog = new ReadinessCatalog;
            $report['problems'] = [...$report['problems'], ...$catalog->inspect(pdo: $pdo, physical: SchemaValues::map($head['physical']), digest: SchemaValues::text($head['snapshot_digest']))];
            $step = 'models';
            foreach ($schema->storage->models as $entity => $class) {
                $changes = new ModelBindings()->changes(schema: $schema, entity: $entity);
                if ($changes !== []) {
                    $report['problems'][] = ['code' => 'MODEL_BINDING_INVALID', 'entity' => $entity, 'component' => SchemaValues::map(SchemaValues::map($schema->jsonSerialize()['entities'])[$entity])['owner'],
                        'step' => $step, 'path' => $class, 'action' => SchemaValues::text($changes['instruction']), 'required' => $changes];
                }
            }
            $step = 'outbox';
            $outboxProblems = array_filter($report['problems'], fn (array $problem): bool => isset($problem['entity']) && $problem['entity'] === 'outbox');
            if ($outboxProblems === []) {
                $table = SchemaValues::text(SchemaValues::map(SchemaValues::map($head['physical'])['location'])['schema']).'.'.SchemaValues::text(SchemaValues::map(SchemaValues::map($head['physical'])['tables'])['outbox']);
                $report['deliveries'] = $catalog->deliveries(pdo: $pdo, table: $table, panel: $schema->panel);
            }
            $report['status'] = $report['problems'] === [] ? 'ready' : 'unready';
        } catch (\Throwable $error) {
            $code = match (true) {
                $error instanceof GenerationFailure => $error->getMessage(),
                $error instanceof InvalidSchemaException => SchemaValues::text($error->detail()['rule']),
                default => 'SCHEMA_DIAGNOSTIC_UNAVAILABLE',
            };
            $report['problems'][] = ['code' => $code, 'step' => $step, 'path' => $path,
                'action' => match (true) {
                    $code === 'SCHEMA_GENERATED_PENDING' => 'Review migrate:status and run php artisan migrate --database='.$panel->storage->connection.' for the pending consumer snapshots.',
                    $code === 'SCHEMA_UPGRADE_REQUIRED' => 'Review the selected module versions and run php artisan sockit:panel:migrations '.$panel->id->value.' --upgrade, then apply the generated migration.',
                    $step === 'history' => 'Restore the consumer history and immutable migration files from version control; generate initial migrations if never installed.',
                    $step === 'ledger' => 'Check migrate:status on the panel connection; apply pending migrations or restore the complete applied ancestry.',
                    $step === 'identity' => 'Verify the panel connection, deployment descriptor, frozen physical map and other panel aliases. Do not migrate a different database.',
                    $step === 'models' => 'Restore or update the consumer concrete model bindings and casts; retry in a fresh Artisan process.',
                    default => 'Inspect the indicated schema prerequisite and restore compatible consumer files/storage; doctor never repairs storage.',
                }];
        } finally {
            if ($started && $connection instanceof Connection) {
                try {
                    $connection->getPdo()->rollBack();
                } catch (\Throwable) {
                    $report['status'] = 'unready';
                    $report['problems'][] = ['code' => 'SCHEMA_DIAGNOSTIC_ROLLBACK_FAILED', 'step' => 'transaction', 'action' => 'Discard this connection and retry doctor in a fresh process.'];
                }
            }
        }

        return $report;
    }
}
