<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use PDO;

/** Required catalog invariants, independent of rollback's deliberately exact fingerprint. */
final class ReadinessCatalog
{
    /** @param array<string, mixed> $physical
     * @return list<array{code:string, entity:string, component:mixed, step:string, path:string, action:string}> */
    public function inspect(PDO $pdo, array $physical, string $digest): array
    {
        $problems = [];
        $schema = SchemaValues::text(SchemaValues::map($physical['location'])['schema']);
        foreach (SchemaValues::map($physical['entities']) as $entity => $value) {
            $spec = SchemaValues::map($value);
            $table = SnapshotDdl::qualified(schema: $schema, table: SchemaValues::text($spec['table']));
            $add = static function (string $code, string $path) use (&$problems, $entity, $spec): void {
                $problems[] = ['code' => $code, 'entity' => $entity, 'component' => $spec['owner'], 'step' => 'catalog', 'path' => $path,
                    'action' => 'Restore the required frozen table/column/index/constraint and ownership from the consumer migration. Preserve host data; doctor performs no repairs.'];
            };
            $rows = $this->rows($pdo, "SELECT c.oid::text AS oid, c.relkind, c.relpersistence, c.relrowsecurity, c.relforcerowsecurity, c.relispartition, obj_description(c.oid, 'pg_class') AS receipt,
                (SELECT count(*) FROM pg_trigger t WHERE t.tgrelid=c.oid AND NOT t.tgisinternal) AS triggers,
                (SELECT count(*) FROM pg_rewrite r WHERE r.ev_class=c.oid) AS rules FROM pg_class c WHERE c.oid=to_regclass(?)", [$table]);
            if ($rows === []) {
                $add('SCHEMA_TABLE_MISSING', $table);

                continue;
            }
            $row = $rows[0];
            if ($row['relkind'] !== 'r' || $row['relpersistence'] !== 'p' || $row['relrowsecurity'] !== false || $row['relforcerowsecurity'] !== false || $row['relispartition'] !== false || $row['triggers'] !== 0 || $row['rules'] !== 0) {
                $add('SCHEMA_TABLE_INCOMPATIBLE', $table);
            }
            $receipt = $row['receipt'];
            if (! is_string($receipt) || preg_match('/^sockit\.snapshot\.v1:'.preg_quote($digest, '/').':'.preg_quote(SchemaValues::text($row['oid']), '/').':[a-f0-9]{32}$/D', $receipt) !== 1) {
                $add('SCHEMA_OWNERSHIP_CHANGED', $table);
            }
            $columns = [];
            foreach ($this->rows($pdo, 'SELECT a.attname, format_type(a.atttypid,a.atttypmod) AS type, a.attnotnull, a.attidentity, a.attgenerated, co.collname AS collation, pg_get_expr(d.adbin,d.adrelid) AS default_expr FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum LEFT JOIN pg_collation co ON co.oid=a.attcollation WHERE a.attrelid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped', [$table]) as $column) {
                $columns[SchemaValues::text($column['attname'])] = $column;
            }
            $required = SchemaValues::map($spec['columns']);
            $model = SchemaValues::map($spec['model']);
            $primary = SchemaValues::map($spec['primary']);
            foreach ($required as $name => $definition) {
                $definition = SchemaValues::map($definition);
                $column = $columns[$name] ?? null;
                if ($column === null) {
                    $add('SCHEMA_COLUMN_MISSING', $table.'.'.$name);

                    continue;
                }
                $length = $definition['length'];
                assert(is_int($length) || $length === null);
                $type = match ($definition['type']) {
                    'string' => 'character varying('.(string) $length.')',
                    'json' => 'jsonb', 'timestamp' => 'timestamp(6) with time zone', default => $definition['type'],
                };
                $identity = $model['incrementing'] === true && $primary['columns'] === [$name];
                $defaultValue = $definition['default'];
                assert(is_string($defaultValue) || is_int($defaultValue) || is_bool($defaultValue) || $defaultValue === null);
                $default = $definition['hasDefault'] === true ? SnapshotDdl::literal($defaultValue) : null;
                if ($column['type'] !== $type || $column['attnotnull'] !== ! $definition['nullable'] || $column['attidentity'] !== ($identity ? 'd' : '') || $column['attgenerated'] !== ''
                    || ($definition['collation'] !== null && $column['collation'] !== $definition['collation'])
                    || $this->expression($column['default_expr']) !== $this->expression($default)) {
                    $add('SCHEMA_COLUMN_INCOMPATIBLE', $table.'.'.$name);
                }
            }
            foreach (array_diff_key($columns, $required) as $name => $column) {
                if ($column['attnotnull'] === true && $column['default_expr'] === null && $column['attidentity'] === '' && $column['attgenerated'] === '') {
                    $add('SCHEMA_HOST_COLUMN_REQUIRES_VALUE', $table.'.'.$name);
                }
            }
            $expected = [SchemaValues::text($primary['name']) => 'PRIMARY KEY ('.SnapshotDdl::columns($primary['columns']).')'];
            foreach (SchemaValues::map($spec['checks']) as $check) {
                $check = SchemaValues::map($check);
                $expected[SchemaValues::text($check['name'])] = 'CHECK ('.$this->check(check: $check, columns: $required).')';
            }
            foreach (SchemaValues::map($spec['foreign_keys']) as $key) {
                $key = SchemaValues::map($key);
                $delete = match ($key['on_delete']) {
                    'cascade' => ' ON DELETE CASCADE', 'set_null' => ' ON DELETE SET NULL', default => ' ON DELETE RESTRICT'
                };
                $expected[SchemaValues::text($key['name'])] = 'FOREIGN KEY ('.SnapshotDdl::columns($key['columns']).') REFERENCES '.SnapshotDdl::qualified(schema: SchemaValues::text($key['schema']), table: SchemaValues::text($key['table'])).'('.SnapshotDdl::columns($key['references']).')'.$delete;
            }
            $actual = [];
            foreach ($this->rows($pdo, 'SELECT conname, convalidated, condeferrable, condeferred, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=to_regclass(?)', [$table]) as $constraint) {
                $name = SchemaValues::text($constraint['conname']);
                $actual[$name] = true;
                if (! isset($expected[$name]) || $constraint['convalidated'] !== true || $constraint['condeferrable'] !== false || $constraint['condeferred'] !== false || $this->expression($constraint['definition']) !== $this->expression($expected[$name])) {
                    $add('SCHEMA_CONSTRAINT_INCOMPATIBLE', $table.'.'.$name);
                }
            }
            foreach (array_diff_key($expected, $actual) as $name => $_) {
                $add('SCHEMA_CONSTRAINT_MISSING', $table.'.'.$name);
            }
            $indexes = [];
            foreach ($this->rows($pdo, 'SELECT c.relname, i.indisunique, i.indisvalid, i.indisready, i.indimmediate, pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE i.indrelid=to_regclass(?)', [$table]) as $index) {
                $indexes[SchemaValues::text($index['relname'])] = $index;
            }
            $knownIndexes = [SchemaValues::text($primary['name'])];
            foreach (SchemaValues::map($spec['indexes']) as $value) {
                $knownIndexes[] = SchemaValues::text(SchemaValues::map($value)['name']);
            }
            foreach ($indexes as $name => $index) {
                if ($index['indisunique'] === true && ! in_array($name, $knownIndexes, true)) {
                    $add('SCHEMA_UNEXPECTED_UNIQUE_INDEX', $table.'.'.$name);
                }
            }
            foreach ([['name' => $primary['name'], 'columns' => $primary['columns'], 'unique' => true], ...array_values(SchemaValues::map($spec['indexes']))] as $value) {
                $index = SchemaValues::map($value);
                $name = SchemaValues::text($index['name']);
                $actualIndex = $indexes[$name] ?? null;
                $definition = 'CREATE '.($index['unique'] === true ? 'UNIQUE ' : '').'INDEX '.$name.' ON '.$table.' USING btree ('.SnapshotDdl::columns($index['columns']).')';
                if ($actualIndex === null || $actualIndex['indisvalid'] !== true || $actualIndex['indisready'] !== true || $actualIndex['indimmediate'] !== true || $this->expression($actualIndex['definition']) !== $this->expression($definition)) {
                    $add('SCHEMA_INDEX_INCOMPATIBLE', $table.'.'.$name);
                }
            }
        }

        return $problems;
    }

    /** @return array<string, mixed> */
    public function deliveries(PDO $pdo, string $table, string $panel): array
    {
        $result = [];
        foreach ($this->rows($pdo, 'SELECT deliveries FROM '.$table.' WHERE panel=?', [$panel]) as $row) {
            $states = SchemaValues::map(json_decode(SchemaValues::text($row['deliveries']), true, flags: JSON_THROW_ON_ERROR));
            foreach ($states as $consumer => $value) {
                $state = SchemaValues::map($value);
                $status = SchemaValues::text($state['status'] ?? null);
                $reason = $state['reason'] ?? $state['quarantine_reason'] ?? null;
                $key = $consumer.':'.$status;
                $previous = $result[$key] ?? ['consumer' => $consumer, 'status' => $status, 'count' => 0, 'reasons' => []];
                $previous['count']++;
                if (is_string($reason) && ! in_array($reason, $previous['reasons'], true)) {
                    $previous['reasons'][] = $reason;
                }
                $result[$key] = $previous;
            }
        }

        return $result;
    }

    /** pg_get_constraintdef's canonical shape for the finite declarative check vocabulary.
     * @param array<string, mixed> $check
     * @param array<string, mixed> $columns */
    private function check(array $check, array $columns): string
    {
        $names = $check['columns'];
        $values = $check['values'];
        assert(is_array($names) && is_array($values));
        $column = SnapshotDdl::identifier(SchemaValues::text($names[0]));
        $type = SchemaValues::map($columns[SchemaValues::text($names[0])])['type'];
        $text = in_array($type, ['string', 'uuid'], true) ? '('.$column.')' : $column;
        $literal = static fn (mixed $value): string => SnapshotDdl::literal(SchemaValues::text($value));
        $list = 'ARRAY['.implode(',', array_map($literal, $values)).']';

        return match ($check['kind']) {
            'one_of' => '('.$text.' = ANY ('.($type === 'string' ? '('.$list.')' : $list).'))',
            'byte_length' => '((octet_length('.$text.') >= 1) AND (octet_length('.$text.') <= '.SchemaValues::text($values[0]).'))',
            'nonnull_iff' => '(('.$column.' IS NOT NULL) = (NOT ('.SnapshotDdl::identifier(SchemaValues::text($names[1])).' IS DISTINCT FROM '.$literal($values[0]).')))',
            'matches' => '('.$text.' ~ '.$literal($values[0]).')',
            'json_object' => '(jsonb_typeof('.$column.") = 'object')",
            'positive' => '('.$column.' >= 1)',
            default => throw new \LogicException('Unsupported declarative check'),
        };
    }

    /** Normalize only deparser decoration; literals remain exact, including spaces/parentheses. */
    private function expression(mixed $expression): string
    {
        if ($expression === null) {
            return '';
        }
        $sql = SchemaValues::text($expression);
        preg_match_all('/(?:E)?\'(?:\'\'|\\\\.|[^\'])*\'|"[^"]*"|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|[^\s]/', $sql, $matches);
        $tokens = [];
        $raw = $matches[0];
        foreach ($raw as $i => $token) {
            $token = $raw[$i];
            // Only deparser casts on the known column types; retain grouping and literals.
            if ($token === ':' && ($raw[$i + 1] ?? null) === ':' && in_array($raw[$i + 2] ?? null, ['text', 'character'], true)) {
                $raw[$i] = $raw[$i + 1] = $raw[$i + 2] = '';
                $end = $i + 3;
                if (($raw[$end] ?? null) === 'varying') {
                    $raw[$end++] = '';
                }
                if (($raw[$end] ?? null) === '[' && ($raw[$end + 1] ?? null) === ']') {
                    $raw[$end] = $raw[$end + 1] = '';
                }

                continue;
            }
            if ($token === '') {
                continue;
            }
            if (str_starts_with($token, "E'")) {
                $token = substr($token, 1);
            } elseif (str_starts_with($token, '"')) {
                $token = substr($token, 1, -1);
            } elseif (! str_starts_with($token, "'")) {
                $token = strtolower($token);
            }
            $tokens[] = $token;
        }

        return implode(' ', $tokens);
    }

    /** @param list<string> $bindings
     * @return list<array<string, mixed>> */
    private function rows(PDO $pdo, string $sql, array $bindings): array
    {
        $query = $pdo->prepare($sql);
        $query->execute($bindings);

        return array_values(array_map(SchemaValues::map(...), $query->fetchAll(PDO::FETCH_ASSOC)));
    }
}
