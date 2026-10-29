<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

/** Compare frozen physical descriptors. No module callbacks and no destructive diff vocabulary. */
final class AdditiveDdl
{
    /** @param array<string, mixed> $previous
     * @return array{up:list<string>, down:list<string>, tables:list<string>, proofs:list<string>} */
    public function compile(array $previous, PhysicalSchema $current): array
    {
        $old = SchemaValues::map($previous['entities']);
        $new = SchemaValues::map($current->toArray()['entities']);
        $ddl = new SnapshotDdl;
        $up = $down = $foreign = $dropForeign = $tables = $proofs = [];
        foreach ($old as $entity => $value) {
            $before = SchemaValues::map($value);
            $after = SchemaValues::map($new[$entity] ?? null);
            foreach (['owner', 'table', 'primary', 'model', 'key_policy'] as $key) {
                SchemaValues::require(valid: SchemaValues::json($before[$key]) === SchemaValues::json($after[$key]), key: $entity.'.'.$key, rule: 'SCHEMA_HISTORY_CHANGED');
            }
            $table = SnapshotDdl::qualified(schema: $current->location->schema, table: SchemaValues::text($after['table']));
            $tables[] = $table;
            $projection = ['*'];
            foreach (['columns', 'indexes', 'foreign_keys', 'checks'] as $section) {
                $prior = SchemaValues::map($before[$section]);
                $next = SchemaValues::map($after[$section]);
                foreach ($prior as $name => $definition) {
                    SchemaValues::require(valid: isset($next[$name]) && SchemaValues::json($definition) === SchemaValues::json($next[$name]), key: $entity.'.'.$name, rule: 'SCHEMA_HISTORY_CHANGED');
                }
                foreach (array_diff_key($next, $prior) as $name => $value) {
                    $spec = SchemaValues::map($value);
                    $quoted = SnapshotDdl::identifier(SchemaValues::text($spec['name'] ?? $name));
                    if ($section === 'columns') {
                        SchemaValues::require(valid: $spec['nullable'] === true || ($spec['hasDefault'] === true && $spec['default'] !== null), key: $entity.'.'.$name, rule: 'UPGRADE_REQUIRED_COLUMN_UNSAFE');
                        $up[] = 'ALTER TABLE '.$table.' ADD COLUMN '.$quoted.' '.$ddl->column(definition: $spec, identity: false);
                        $down[] = 'ALTER TABLE '.$table.' DROP COLUMN '.$quoted.' RESTRICT';
                        $default = $spec['hasDefault'] === true ? $spec['default'] : null;
                        assert(is_string($default) || is_int($default) || is_bool($default) || $default === null);
                        $projection[] = 'CAST('.SnapshotDdl::literal($default).' AS '.$ddl->type($spec).') AS '.$quoted;
                    } elseif ($section === 'indexes') {
                        $columns = SnapshotDdl::columns($spec['columns']);
                        $up[] = 'CREATE '.($spec['unique'] === true ? 'UNIQUE ' : '').'INDEX '.$quoted.' ON '.$table.' ('.$columns.')';
                        $down[] = 'DROP INDEX '.SnapshotDdl::qualified(schema: $current->location->schema, table: SchemaValues::text($spec['name']));
                        if ($spec['unique'] === true) {
                            $nonNull = str_replace(', ', ' IS NOT NULL AND ', $columns).' IS NOT NULL';
                            $proofs[] = 'SELECT 1 FROM (SELECT '.implode(', ', $projection).' FROM '.$table.') AS candidate WHERE '.$nonNull.' GROUP BY '.$columns.' HAVING count(*) > 1 LIMIT 1';
                        }
                    } elseif ($section === 'checks') {
                        $predicate = $ddl->predicate($spec);
                        $up[] = 'ALTER TABLE '.$table.' ADD CONSTRAINT '.$quoted.' CHECK ('.$predicate.')';
                        $down[] = 'ALTER TABLE '.$table.' DROP CONSTRAINT '.$quoted;
                        $proofs[] = 'SELECT 1 FROM (SELECT '.implode(', ', $projection).' FROM '.$table.') AS candidate WHERE NOT ('.$predicate.') LIMIT 1';
                    } else {
                        $target = SchemaValues::text($spec['entity']);
                        $columns = $spec['columns'];
                        $references = $spec['references'];
                        assert(is_array($columns) && is_array($references));
                        $equal = $nonNull = [];
                        foreach ($columns as $i => $column) {
                            $local = 'candidate.'.SnapshotDdl::identifier(SchemaValues::text($column));
                            $equal[] = $local.' = target.'.SnapshotDdl::identifier(SchemaValues::text($references[$i]));
                            $nonNull[] = $local.' IS NOT NULL';
                        }
                        $remote = SnapshotDdl::qualified(schema: SchemaValues::text($spec['schema']), table: SchemaValues::text($spec['table']));
                        $foreign[] = 'ALTER TABLE '.$table.' ADD CONSTRAINT '.$quoted.' FOREIGN KEY ('.SnapshotDdl::columns($columns).') REFERENCES '.$remote.' ('.SnapshotDdl::columns($references).') ON DELETE '.match ($spec['on_delete']) {
                            'restrict' => 'RESTRICT', 'cascade' => 'CASCADE', 'set_null' => 'SET NULL', default => throw new \LogicException('Invalid compiled foreign key'),
                        };
                        $dropForeign[] = 'ALTER TABLE '.$table.' DROP CONSTRAINT '.$quoted;
                        // A new target table starts empty. Existing non-null references cannot be valid yet.
                        $proofs[] = 'SELECT 1 FROM (SELECT '.implode(', ', $projection).' FROM '.$table.') AS candidate WHERE '.implode(' AND ', $nonNull)
                            .(isset($old[$target]) ? ' AND NOT EXISTS (SELECT 1 FROM '.$remote.' AS target WHERE '.implode(' AND ', $equal).')' : '').' LIMIT 1';
                    }
                }
            }
        }
        // Reuse initial DDL for newly owned entities, including their final aggregated steps.
        $added = array_diff_key($new, $old);
        $steps = [];
        foreach ($added as $entity => $value) {
            $steps[] = ['action' => 'create', 'entity' => $entity];
        }
        $descriptor = $current->toArray();
        $descriptor['entities'] = $added;
        $program = $ddl->compile(draft: ['components' => ['new' => ['steps' => $steps]], 'dependency_order' => ['new']],
            physical: new PhysicalSchema(location: $current->location, descriptor: $descriptor, relations: []));

        $newForeign = array_values(array_filter($program['up'], fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE ')));
        $newCreates = array_values(array_filter($program['up'], fn (string $sql): bool => ! str_starts_with($sql, 'ALTER TABLE ')));
        $newDropForeign = array_values(array_filter($program['down'], fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE ')));
        $newDropTables = array_values(array_filter($program['down'], fn (string $sql): bool => ! str_starts_with($sql, 'ALTER TABLE ')));

        return ['up' => [...$newCreates, ...$up, ...$newForeign, ...$foreign],
            'down' => [...array_reverse($dropForeign), ...$newDropForeign, ...array_reverse($down), ...$newDropTables],
            'tables' => [...$tables, ...$program['tables']], 'proofs' => $proofs];
    }
}
