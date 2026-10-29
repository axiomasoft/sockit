<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use Illuminate\Database\Connection;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Internal\Generation\GenerationPaths;
use SocKit\Internal\Generation\GenerationPlan;
use SocKit\Schema\ModelBindings;
use SocKit\Schema\ModelEmitter;
use SocKit\Schema\PanelSchema;
use SocKit\Schema\SnapshotRenderer;

final class MigrationPlanner
{
    /** @param list<string> $ran */
    public function plan(string $root, PanelSchema $desired, Connection $connection, mixed $deployment, array $ran, bool $upgrade): GenerationPlan
    {
        GenerationFailure::require(valid: realpath($root) === $root, rule: 'GENERATION_ROOT_INVALID');
        $chain = new MigrationHistory()->read(root: $root, panel: $desired->panel);
        GenerationFailure::require(valid: $upgrade || $chain === [], rule: 'MIGRATION_HISTORY_EXISTS: use --upgrade');
        GenerationFailure::require(valid: ! $upgrade || $chain !== [], rule: 'MIGRATION_HISTORY_MISSING: generate the initial snapshot first');
        $marker = '_sockit_'.substr(hash('sha256', $desired->panel), 0, 16);
        $names = array_map(fn (array $record): string => SchemaValues::text($record['migration']), $chain);
        $applied = array_values(array_filter($ran, fn (string $name): bool => str_ends_with($name, $marker)));
        sort($applied);
        GenerationFailure::require(valid: $applied === array_slice($names, 0, count($applied)), rule: 'MIGRATION_LEDGER_NOT_PREFIX');
        $previous = $chain === [] ? null : $chain[array_key_last($chain)];
        $schema = $previous === null ? $desired : MigrationHistory::union(previous: SchemaValues::map($previous['schema']), desired: $desired);
        $preflight = new StoragePreflight;
        $physical = $preflight->inspect(schema: $schema, connection: $connection, deployment: StorageDeployment::fromArray($deployment));
        if ($previous !== null) {
            $oldPhysical = SchemaValues::map($previous['physical']);
            foreach (['location', 'driver_prefix'] as $key) {
                GenerationFailure::require(valid: SchemaValues::json($oldPhysical[$key]) === SchemaValues::json($physical->toArray()[$key]), rule: 'MIGRATION_BINDING_CHANGED');
            }
        }
        // Prove the actual applied head, independently of the latest generated (possibly pending) head.
        $appliedCount = count($applied);
        if ($appliedCount > 0) {
            $head = $chain[$appliedCount - 1];
            $snapshot = MigrationHistory::snapshot((string) file_get_contents($root.'/database/migrations/'.SchemaValues::text($head['migration']).'.php'));
            $tables = SchemaValues::map($snapshot['program'])['tables'];
            assert(is_array($tables));
            SnapshotCatalog::verify(pdo: $connection->getPdo(), tables: array_values(array_map(SchemaValues::text(...), $tables)), digest: SchemaValues::text($head['snapshot_digest']));
        }
        // Everything beyond the applied prefix must still be vacant, including pending ancestors.
        $appliedRelations = $appliedCount > 0 ? $chain[$appliedCount - 1]['relations'] : [];
        assert(is_array($appliedRelations));
        $vacant = array_values(array_diff($physical->relations, array_map(SchemaValues::text(...), $appliedRelations)));
        $preflight->assertVacant(connection: $connection, schema: new PhysicalSchema(location: $physical->location, descriptor: $physical->toArray(), relations: $vacant));
        $delta = $previous === null ? null : new AdditiveDdl()->compile(previous: SchemaValues::map($previous['physical']), current: $physical);
        $models = $this->models(root: $root, schema: $schema);
        $sources = $models['sources'];
        $compatibility = ['model_changes' => $models['changes'], 'models_compatible' => $models['changes'] === []];
        if ($previous !== null && $schema->digest === SchemaValues::map($previous['schema'])['digest']) {
            return new GenerationPlan(root: $root, sources: $sources, manifest: ['status' => $sources === [] ? 'no_op' : 'missing_models', 'panel' => $schema->panel,
                'files' => array_keys($sources), 'applied' => $appliedCount, 'generated' => count($chain), ...$compatibility]);
        }
        if ($previous === null) {
            $source = new SnapshotRenderer()->render(schema: $schema, connection: $connection, deployment: $deployment);
        } else {
            assert($delta !== null);
            // Data predicates refer to the latest baseline. Do not pretend pending DDL is applied.
            GenerationFailure::require(valid: $delta['proofs'] === [] || $appliedCount === count($chain), rule: 'MIGRATION_APPLY_PENDING_FOR_DATA_PROOF');
            foreach ($delta['proofs'] as $sql) {
                $query = $connection->getPdo()->query($sql);
                GenerationFailure::require(valid: $query !== false && $query->fetchColumn() === false, rule: 'MIGRATION_DATA_CONSTRAINT_FAILED');
            }
            $parentSnapshot = MigrationHistory::snapshot((string) file_get_contents($root.'/database/migrations/'.SchemaValues::text($previous['migration']).'.php'));
            $previousRelations = $previous['relations'];
            assert(is_array($previousRelations));
            $json = SchemaValues::json(['format_version' => 1, 'mode' => 'additive_snapshot', 'connection' => $schema->storage->connection,
                'schema' => $schema->jsonSerialize(), 'physical' => $physical->toArray(), 'endpoints' => $physical->location->deployment->endpoints(),
                'parent_snapshot_digest' => $previous['snapshot_digest'], 'parent_tables' => SchemaValues::map($parentSnapshot['program'])['tables'],
                'relations' => array_values(array_diff($physical->relations, array_map(SchemaValues::text(...), $previousRelations))), 'program' => array_diff_key($delta, ['proofs' => true])]);
            $stub = file_get_contents(__DIR__.'/additive.php.stub');
            GenerationFailure::require(valid: is_string($stub), rule: 'MIGRATION_STUB_MISSING');
            $source = strtr($stub, ['{{SNAPSHOT}}' => base64_encode($json), '{{DIGEST}}' => hash('sha256', $json), '{{CONNECTION}}' => var_export($schema->storage->connection, true)]);
        }
        $sequence = count($chain) + 1;
        $stamp = max(gmdate('Y_m_d_His'), $previous === null ? '' : substr(SchemaValues::text($previous['migration']), 0, 17));
        $name = $stamp.'_'.sprintf('%06d', $sequence).$marker;
        $migration = 'database/migrations/'.$name.'.php';
        $manifest = 'database/sockit/'.$schema->panel.'/'.sprintf('%06d.json', $sequence);
        $snapshot = MigrationHistory::snapshot($source);
        $record = ['format_version' => 1, 'panel' => $schema->panel, 'sequence' => $sequence, 'parent' => $previous['digest'] ?? null,
            'migration' => $name, 'migration_sha256' => hash('sha256', $source), 'snapshot_digest' => hash('sha256', SchemaValues::json($snapshot)),
            'schema' => $schema->jsonSerialize(), 'physical' => $physical->toArray(), 'relations' => $physical->relations];
        $record['digest'] = hash('sha256', SchemaValues::json($record));
        // Manifest last: the history never claims a migration/model before its exclusive install.
        $sources[$migration] = $source;
        $sources[$manifest] = SchemaValues::json($record)."\n";
        foreach (array_keys($sources) as $path) {
            GenerationPaths::check(root: $root, relative: $path);
        }

        return new GenerationPlan(root: $root, sources: $sources, manifest: ['status' => 'planned', 'panel' => $schema->panel, 'files' => array_keys($sources),
            ...$compatibility,
            'outputs' => array_map(fn (string $source): string => hash('sha256', $source), $sources),
            'tables' => $physical->toArray()['tables'], 'models' => $schema->storage->models, 'components' => $schema->jsonSerialize()['components'],
            'migration' => $migration, 'ancestry' => $manifest, 'digest' => $record['digest'], 'applied' => $appliedCount, 'generated' => count($chain),
            'instruction' => 'Run php artisan migrate --database='.$schema->storage->connection.' after reviewing these consumer-owned files.']);
    }

    /** @return array{sources:array<string, string>, changes:array<string, array<string, mixed>>} */
    private function models(string $root, PanelSchema $schema): array
    {
        $mapping = GenerationPaths::mapping($root);
        $sources = $classes = $changes = [];
        $rendered = new ModelEmitter()->render($schema);
        foreach ($schema->storage->models as $entity => $class) {
            $path = str_starts_with($class, $mapping['namespace']) ? $mapping['directory'].'/'.str_replace('\\', '/', substr($class, strlen($mapping['namespace']))).'.php' : null;
            if ($path !== null) {
                GenerationPaths::check(root: $root, relative: dirname($path), vacant: false);
                GenerationFailure::require(valid: ! is_link($root.'/'.$path), rule: 'GENERATION_PATH_SYMLINK');
            }
            // Existing custom files are consumer-owned, including ones not yet autoloaded.
            if (($path !== null && is_file($root.'/'.$path)) || class_exists($class)) {
                $required = new ModelBindings()->changes(schema: $schema, entity: $entity);
                if ($required !== []) {
                    $changes[$entity] = [...$required, 'path' => $path];
                }

                continue;
            }
            GenerationFailure::require(valid: $path !== null, rule: 'MIGRATION_MODEL_MAPPING_MISSING');
            GenerationPaths::check(root: $root, relative: $path);
            $sources[$path] = $rendered[$class];
            $classes[] = $class;
        }
        GenerationPaths::classes(root: $root, directory: $mapping['directory'], classes: $classes);

        return ['sources' => $sources, 'changes' => $changes];
    }
}
