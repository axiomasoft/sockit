<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use Packman\Attributes\Internal;
use SocKit\Internal\Generation\GenerationFailure;
use SocKit\Internal\Generation\GenerationPaths;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\PanelSchema;

#[Internal]
final class MigrationHistory
{
    /** @return list<array<string, mixed>> */
    public function read(string $root, string $panel): array
    {
        $relative = 'database/sockit/'.$panel;
        $directory = GenerationPaths::check(root: $root, relative: $relative, vacant: false);
        $entries = is_dir($directory) ? array_values(array_diff(scandir($directory) ?: [], ['.', '..'])) : [];
        sort($entries);
        $chain = [];
        $parent = null;
        $names = [];
        $marker = substr(hash('sha256', $panel), 0, 16);
        $migrationsChecked = false;
        foreach ($entries as $i => $entry) {
            GenerationFailure::require(valid: $entry === sprintf('%06d.json', $i + 1), rule: 'MIGRATION_HISTORY_GAP_OR_BRANCH');
            $path = $directory.'/'.$entry;
            GenerationFailure::require(valid: ! is_link($path) && is_file($path), rule: 'MIGRATION_HISTORY_PATH_INVALID');
            $record = SchemaValues::map(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
            $digest = $record['digest'] ?? null;
            unset($record['digest']);
            GenerationFailure::require(valid: $digest === hash('sha256', SchemaValues::json($record)) && ($record['parent'] ?? null) === $parent && ($record['sequence'] ?? null) === $i + 1
                && ($record['panel'] ?? null) === $panel && ($record['format_version'] ?? null) === 1, rule: 'MIGRATION_HISTORY_TAMPERED');
            $name = SchemaValues::text($record['migration'] ?? null);
            GenerationFailure::require(valid: preg_match('/^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[0-9]{6}_sockit_'.$marker.'$/D', $name) === 1
                && ($names === [] || $name > $names[array_key_last($names)]), rule: 'MIGRATION_HISTORY_NAME_INVALID');
            if (! $migrationsChecked) {
                GenerationPaths::check(root: $root, relative: 'database/migrations', vacant: false);
                $migrationsChecked = true;
            }
            $file = $root.'/database/migrations/'.$name.'.php';
            GenerationFailure::require(valid: ! is_link($file) && is_file($file) && hash_file('sha256', $file) === ($record['migration_sha256'] ?? null), rule: 'MIGRATION_FILE_TAMPERED');
            $snapshot = self::snapshot((string) file_get_contents($file));
            GenerationFailure::require(valid: SchemaValues::json($record['schema'] ?? null) === SchemaValues::json($snapshot['schema'])
                && SchemaValues::json($record['physical'] ?? null) === SchemaValues::json($snapshot['physical'])
                && ($record['snapshot_digest'] ?? null) === hash('sha256', SchemaValues::json($snapshot)), rule: 'MIGRATION_HISTORY_TAMPERED');
            $schema = self::schema(SchemaValues::map($record['schema']));
            if ($chain !== []) {
                $ancestor = $chain[array_key_last($chain)];
                $union = self::union(previous: SchemaValues::map($ancestor['schema']), desired: $schema);
                GenerationFailure::require(valid: $union->digest === $schema->digest && ($snapshot['parent_snapshot_digest'] ?? null) === $ancestor['snapshot_digest'], rule: 'MIGRATION_HISTORY_TAMPERED');
            } else {
                GenerationFailure::require(valid: $snapshot['mode'] === 'initial_snapshot', rule: 'MIGRATION_HISTORY_TAMPERED');
            }
            $parent = $digest;
            $chain[] = [...$record, 'digest' => $digest];
            $names[] = $name;
        }
        $files = glob($root.'/database/migrations/*_sockit_'.$marker.'.php') ?: [];
        $actual = array_map(fn (string $path): string => basename($path, '.php'), $files);
        sort($actual);
        GenerationFailure::require(valid: $actual === $names, rule: 'MIGRATION_ORPHAN_FILE');

        return $chain;
    }

    /** @return array<string, mixed> */
    public static function snapshot(string $source): array
    {
        GenerationFailure::require(valid: preg_match("/public const string SNAPSHOT = '([A-Za-z0-9+\/=]+)';/", $source, $encoded) === 1
            && preg_match("/public const string DIGEST = '([a-f0-9]{64})';/", $source, $digest) === 1, rule: 'MIGRATION_SNAPSHOT_INVALID');
        $json = base64_decode($encoded[1], true);
        GenerationFailure::require(valid: is_string($json) && hash('sha256', $json) === $digest[1], rule: 'MIGRATION_SNAPSHOT_INVALID');

        return SchemaValues::map(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    public static function schema(array $data): PanelSchema
    {
        $order = $data['dependency_order'];
        assert(is_array($order) && array_is_list($order));
        $schema = new PanelSchema(panel: SchemaValues::text($data['panel']),
            storage: new PanelStorage(connection: SchemaValues::text($data['connection']), schema: SchemaValues::text($data['schema']), prefix: SchemaValues::text($data['prefix']),
                tables: array_map(SchemaValues::text(...), SchemaValues::map($data['tables'])), models: array_map(SchemaValues::text(...), SchemaValues::map($data['models'])), ids: SchemaValues::text($data['ids'])),
            components: SchemaValues::map($data['components']), entities: SchemaValues::map($data['entities']), order: array_map(SchemaValues::text(...), $order));
        GenerationFailure::require(valid: $schema->digest === ($data['digest'] ?? null), rule: 'MIGRATION_SCHEMA_TAMPERED');

        return $schema;
    }

    /** Disabled components stay frozen. Active components must retain every original step and binding.
     * @param array<string, mixed> $previous */
    public static function union(array $previous, PanelSchema $desired): PanelSchema
    {
        $current = $desired->jsonSerialize();
        foreach (['panel', 'connection', 'schema', 'prefix', 'ids'] as $key) {
            GenerationFailure::require(valid: $current[$key] === $previous[$key], rule: 'MIGRATION_BINDING_CHANGED');
        }
        $before = SchemaValues::map($previous['components']);
        $after = SchemaValues::map($current['components']);
        foreach ($before as $id => $value) {
            if (! isset($after[$id])) {
                continue;
            }
            $old = SchemaValues::map($value);
            $new = SchemaValues::map($after[$id]);
            $oldSteps = $old['steps'];
            $newSteps = $new['steps'];
            assert(is_array($oldSteps) && is_array($newSteps) && is_int($old['version']) && is_int($new['version']));
            GenerationFailure::require(valid: $new['version'] >= $old['version'] && $old['dependencies'] === $new['dependencies']
                && SchemaValues::json(array_slice($newSteps, 0, count($oldSteps))) === SchemaValues::json($oldSteps), rule: 'MIGRATION_STEPS_REWRITTEN');
            foreach (array_slice($newSteps, count($oldSteps)) as $step) {
                $step = SchemaValues::map($step);
                GenerationFailure::require(valid: is_int($step['version']) && $step['version'] > $old['version'] && $new['version'] > $old['version'], rule: 'MIGRATION_STEPS_REWRITTEN');
            }
        }
        $oldEntities = SchemaValues::map($previous['entities']);
        $newEntities = SchemaValues::map($current['entities']);
        foreach ($oldEntities as $entity => $value) {
            $owner = SchemaValues::text(SchemaValues::map($value)['owner']);
            GenerationFailure::require(valid: ! isset($after[$owner]) || isset($newEntities[$entity]), rule: 'MIGRATION_ENTITY_REMOVED');
            if (isset($newEntities[$entity])) {
                $oldSpec = SchemaValues::map($value);
                $newSpec = SchemaValues::map($newEntities[$entity]);
                foreach (['owner', 'table', 'primary', 'model', 'key_policy'] as $key) {
                    GenerationFailure::require(valid: SchemaValues::json($oldSpec[$key]) === SchemaValues::json($newSpec[$key]), rule: 'MIGRATION_BINDING_CHANGED');
                }
                foreach (['columns', 'indexes', 'foreign_keys', 'checks'] as $key) {
                    $next = SchemaValues::map($newSpec[$key]);
                    foreach (SchemaValues::map($oldSpec[$key]) as $name => $definition) {
                        GenerationFailure::require(valid: isset($next[$name]) && SchemaValues::json($definition) === SchemaValues::json($next[$name]), rule: 'MIGRATION_ENTITY_REWRITTEN');
                    }
                }
            }
        }
        $components = [...$before, ...$after];
        $entities = [...$oldEntities, ...$newEntities];
        $tables = [...SchemaValues::map($previous['tables']), ...$desired->storage->tables];
        $models = [...SchemaValues::map($previous['models']), ...$desired->storage->models];
        foreach (['tables' => $tables, 'models' => $models] as $map => $values) {
            foreach (SchemaValues::map($previous[$map]) as $entity => $value) {
                GenerationFailure::require(valid: $values[$entity] === $value, rule: 'MIGRATION_BINDING_CHANGED');
            }
        }
        $order = $previous['dependency_order'];
        assert(is_array($order));
        $desiredOrder = $current['dependency_order'];
        assert(is_array($desiredOrder));
        foreach ($desiredOrder as $id) {
            if (! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        ksort($tables);
        ksort($models);
        $storage = $desired->storage;

        return new PanelSchema(panel: $desired->panel, storage: new PanelStorage(connection: $storage->connection, schema: $storage->schema, prefix: $storage->prefix,
            tables: array_map(SchemaValues::text(...), $tables), models: array_map(SchemaValues::text(...), $models), ids: $storage->ids),
            components: $components, entities: $entities, order: array_values(array_map(SchemaValues::text(...), $order)));
    }
}
