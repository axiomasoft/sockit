<?php

declare(strict_types=1);

namespace SocKit\Schema;

use SocKit\Attributes\Api;
use SocKit\Panels\PanelDefinition;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Internal\KernelSchema;
use SocKit\Schema\Internal\SchemaValues;

#[Api(since: '1.0')]
final class PanelSchemaCompiler
{
    /** Validate ALL enabled fragments, even if no panel uses them.
     * @param array<string, SchemaComponent> $components */
    public function validateComponents(array $components): void
    {
        $this->compose($this->ordered($components));
    }

    /** Entity keys admitted by the selected component graph, never by arbitrary overrides.
     * @param list<string> $selected
     * @param array<string, SchemaComponent> $components
     * @return list<string> */
    public function entities(array $selected, array $components): array
    {
        return array_keys($this->compose($this->select(selected: $selected, components: $components)));
    }

    /** Compile only kernel + panel selection. No class autoload, SQL or physical identity probe.
     * @param array<string, SchemaComponent> $components */
    public function compile(PanelDefinition $panel, array $components, ?PanelSchema $previous = null, string $modelNamespace = 'App\\'): PanelSchema
    {
        SchemaValues::require(valid: preg_match('~^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$~D', $modelNamespace) === 1, key: 'models.namespace', rule: 'MODEL_NAMESPACE_REQUIRED');
        $ordered = $this->select(selected: $panel->modules, components: $components);
        $entities = $this->compose($ordered);
        $tables = $panel->storage->tables;
        $models = $panel->storage->models;
        SchemaValues::require(valid: array_diff(array_keys($tables), array_keys($entities)) === [], key: 'tables', rule: 'SCHEMA_ENTITY_UNKNOWN');
        SchemaValues::require(valid: array_diff(array_keys($models), array_keys($entities)) === [], key: 'models', rule: 'SCHEMA_ENTITY_UNKNOWN');
        $history = $specifications = [];
        foreach ($ordered as $id => $component) {
            $steps = [];
            foreach ($component->steps as $step) {
                $steps[] = [...$step->toArray(), 'digest' => $step->digest()];
            }
            $history[$id] = ['version' => $component->version, 'dependencies' => $component->dependencies, 'steps' => $steps];
        }
        foreach ($entities as $entity => $spec) {
            $table = $tables[$entity] ?? $panel->storage->prefix.$entity;
            $tables[$entity] = $table;
            $model = $spec['model'];
            // The hash distinguishes panel IDs that would otherwise normalize to the same namespace.
            $models[$entity] ??= $modelNamespace.'SocKit\\Generated\\Panel'.substr(hash('sha256', $panel->id->value), 0, 16).'\\'.$model->name;
            $indexes = $foreignKeys = $checks = [];
            foreach ($spec['indexes'] as $name => $index) {
                $indexes[$name] = ['name' => $this->constraint(panel: $panel, entity: $entity, kind: 'idx', key: $name), 'columns' => $index->columns, 'unique' => $index->unique];
            }
            foreach ($spec['foreign_keys'] as $name => $key) {
                $foreignKeys[$name] = ['name' => $this->constraint(panel: $panel, entity: $entity, kind: 'fk', key: $name),
                    'columns' => $key->columns, 'entity' => $key->entity, 'references' => $key->references, 'on_delete' => $key->onDelete];
            }
            foreach ($spec['checks'] as $name => $check) {
                $checks[$name] = ['name' => $this->constraint(panel: $panel, entity: $entity, kind: 'ck', key: $name), ...get_object_vars($check)];
            }
            $specifications[$entity] = ['owner' => $spec['owner'], 'table' => $table,
                'columns' => array_map(fn (SchemaColumn $column): array => $column->toArray(), $spec['columns']),
                'primary' => ['name' => $this->constraint(panel: $panel, entity: $entity, kind: 'pk', key: 'primary'), 'columns' => $model->primary],
                'indexes' => $indexes, 'foreign_keys' => $foreignKeys, 'checks' => $checks, 'model' => [...get_object_vars($model), 'class' => $models[$entity]],
                'key_policy' => count($model->primary) > 1 ? 'composite' : ($model->incrementing ? 'incrementing' : ($spec['columns'][$model->primary[0]]->type === 'uuid' ? $panel->storage->ids : 'assigned'))];
        }
        SchemaValues::require(valid: count(array_unique(array_map(strtolower(...), $models))) === count($models), key: 'models', rule: 'SCHEMA_MODEL_COLLISION');
        $storage = new PanelStorage(connection: $panel->storage->connection, schema: $panel->storage->schema, prefix: $panel->storage->prefix, tables: $tables, models: $models, ids: $panel->storage->ids);
        $schema = new PanelSchema(panel: $panel->id->value, storage: $storage, components: $history, entities: $specifications, order: array_keys($ordered));
        if ($previous !== null) {
            $this->appendOnly(previous: $previous, current: $schema);
        }

        return $schema;
    }

    private function constraint(PanelDefinition $panel, string $entity, string $kind, string $key): string
    {
        return 'sk_'.$kind.'_'.substr(hash('sha256', SchemaValues::json([$panel->id->value, $panel->storage->schema, $panel->storage->tables[$entity] ?? $panel->storage->prefix.$entity, $kind, $key])), 0, 24);
    }

    /** @param list<string> $selected
     * @param array<string, SchemaComponent> $components
     * @return array<string, SchemaComponent> */
    private function select(array $selected, array $components): array
    {
        $selection = [];
        foreach ($selected as $id) {
            if (isset($components[$id])) {
                $selection[$id] = $components[$id];
            }
        }

        return $this->ordered($selection);
    }

    /** @param array<string, SchemaComponent> $components
     * @return array<string, SchemaComponent> */
    private function ordered(array $components): array
    {
        SchemaValues::require(valid: ! isset($components[KernelSchema::ID]), key: KernelSchema::ID, rule: 'SCHEMA_OWNER_DUPLICATE');
        foreach ($components as $id => $component) {
            SchemaValues::require(valid: $id === $component->id, key: $id, rule: 'SCHEMA_OWNER_INVALID');
        }
        $pending = [KernelSchema::ID => KernelSchema::component(), ...$components];
        ksort($pending);
        $ordered = [];
        foreach ($pending as $id => $component) {
            foreach ($component->dependencies as $dependency => $minimum) {
                SchemaValues::require(valid: isset($pending[$dependency]) && $pending[$dependency]->version >= $minimum, key: $id.'.dependencies.'.$dependency, rule: 'SCHEMA_DEPENDENCY_MISSING');
            }
        }
        // Deterministic Kahn order. Kernel is an implicit prerequisite for every module.
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $component) {
                if (($id !== KernelSchema::ID && ! isset($ordered[KernelSchema::ID])) || array_diff(array_keys($component->dependencies), array_keys($ordered)) !== []) {
                    continue;
                }
                $ordered[$id] = $component;
                unset($pending[$id]);
                $progress = true;
                break;
            }
            SchemaValues::require(valid: $progress, key: 'dependencies', rule: 'SCHEMA_DEPENDENCY_CYCLE');
        }

        return $ordered;
    }

    /** @param array<string, SchemaComponent> $ordered
     * @return array<string, array{owner:string, columns:array<string, SchemaColumn>, model:SchemaModel, indexes:array<string, SchemaIndex>, foreign_keys:array<string, SchemaForeignKey>, checks:array<string, SchemaCheck>}> */
    private function compose(array $ordered): array
    {
        $entities = [];
        foreach ($ordered as $id => $component) {
            foreach ($component->steps as $step) {
                $entity = $step->entity;
                if ($step->model !== null) {
                    SchemaValues::require(valid: ! isset($entities[$entity]), key: $entity, rule: 'SCHEMA_ENTITY_DUPLICATE');
                    $entities[$entity] = ['owner' => $id, 'columns' => [], 'model' => $step->model, 'indexes' => [], 'foreign_keys' => [], 'checks' => []];
                }
                SchemaValues::require(valid: isset($entities[$entity]), key: $entity, rule: 'SCHEMA_ENTITY_UNKNOWN');
                SchemaValues::require(valid: $entities[$entity]['owner'] === $id, key: $entity, rule: 'SCHEMA_OWNER_INVALID');
                foreach ($step->columns as $column => $definition) {
                    SchemaValues::require(valid: ! isset($entities[$entity]['columns'][$column]), key: $entity.'.'.$column, rule: 'SCHEMA_COLUMN_DUPLICATE');
                    $entities[$entity]['columns'][$column] = $definition;
                }
                $spec = $entities[$entity];
                foreach (array_merge($spec['model']->primary, array_keys($spec['model']->casts), $spec['model']->timestamps ? ['created_at', 'updated_at'] : []) as $column) {
                    SchemaValues::require(valid: isset($spec['columns'][$column]), key: $entity.'.'.$column, rule: 'SCHEMA_COLUMN_UNKNOWN');
                }
                foreach ($spec['model']->primary as $column) {
                    SchemaValues::require(valid: ! $spec['columns'][$column]->nullable, key: $entity.'.primary', rule: 'SCHEMA_KEY_INVALID');
                }
                foreach ($step->indexes as $name => $index) {
                    SchemaValues::require(valid: ! isset($spec['indexes'][$name]), key: $entity.'.'.$name, rule: 'SCHEMA_KEY_DUPLICATE');
                    SchemaValues::require(valid: array_diff($index->columns, array_keys($spec['columns'])) === [], key: $entity.'.'.$name, rule: 'SCHEMA_COLUMN_UNKNOWN');
                    $spec['indexes'][$name] = $index;
                }
                foreach ($step->foreignKeys as $name => $key) {
                    SchemaValues::require(valid: ! isset($spec['foreign_keys'][$name]), key: $entity.'.'.$name, rule: 'SCHEMA_KEY_DUPLICATE');
                    $target = $key->entity === $entity ? $spec : ($entities[$key->entity] ?? null);
                    SchemaValues::require(valid: $target !== null, key: $entity.'.'.$name, rule: 'SCHEMA_ENTITY_UNKNOWN');
                    SchemaValues::require(valid: $target['owner'] === $id || $target['owner'] === KernelSchema::ID || isset($component->dependencies[$target['owner']]), key: $entity.'.'.$name, rule: 'SCHEMA_DEPENDENCY_MISSING');
                    SchemaValues::require(valid: array_diff($key->columns, array_keys($spec['columns'])) === [] && array_diff($key->references, array_keys($target['columns'])) === [], key: $entity.'.'.$name, rule: 'SCHEMA_COLUMN_UNKNOWN');
                    $unique = $target['model']->primary === $key->references || array_any($target['indexes'], fn (SchemaIndex $index): bool => $index->unique && $index->columns === $key->references);
                    SchemaValues::require(valid: $unique, key: $entity.'.'.$name, rule: 'SCHEMA_KEY_INVALID');
                    foreach ($key->columns as $position => $column) {
                        $local = $spec['columns'][$column];
                        $remote = $target['columns'][$key->references[$position]];
                        SchemaValues::require(valid: $local->type === $remote->type && $local->length === $remote->length && $local->collation === $remote->collation && ($key->onDelete !== 'set_null' || $local->nullable), key: $entity.'.'.$name, rule: 'SCHEMA_KEY_INVALID');
                    }
                    $spec['foreign_keys'][$name] = $key;
                }
                foreach ($step->checks as $name => $check) {
                    SchemaValues::require(valid: ! isset($spec['checks'][$name]), key: $entity.'.'.$name, rule: 'SCHEMA_KEY_DUPLICATE');
                    SchemaValues::require(valid: array_diff($check->columns, array_keys($spec['columns'])) === [], key: $entity.'.'.$name, rule: 'SCHEMA_COLUMN_UNKNOWN');
                    $column = $spec['columns'][$check->columns[0]];
                    SchemaValues::require(valid: match ($check->kind) {
                        'positive' => in_array($column->type, ['bigint', 'integer'], true),
                        'one_of' => in_array($column->type, ['text', 'string'], true),
                        'byte_length' => in_array($column->type, ['text', 'string'], true),
                        'matches' => in_array($column->type, ['text', 'string', 'uuid'], true),
                        'json_object' => $column->type === 'json',
                        'nonnull_iff' => $column->nullable && in_array($spec['columns'][$check->columns[1]]->type, ['text', 'string'], true),
                        default => false,
                    }, key: $entity.'.'.$name, rule: 'SCHEMA_CHECK_INVALID');
                    $spec['checks'][$name] = $check;
                }
                $entities[$entity] = $spec;
            }
        }
        ksort($entities);

        return $entities;
    }

    private function appendOnly(PanelSchema $previous, PanelSchema $current): void
    {
        $old = $previous->jsonSerialize();
        $new = $current->jsonSerialize();
        foreach (['format_version', 'panel', 'connection', 'schema', 'prefix', 'ids'] as $key) {
            SchemaValues::require(valid: $old[$key] === $new[$key], key: $key, rule: 'SCHEMA_HISTORY_CHANGED');
        }
        // Fixed entries cannot disappear or change. Only new component versions/steps are allowed.
        foreach (['tables', 'models'] as $map) {
            assert(is_array($old[$map]) && is_array($new[$map]));
            foreach ($old[$map] as $key => $value) {
                SchemaValues::require(valid: ($new[$map][$key] ?? null) === $value, key: $map.'.'.$key, rule: 'SCHEMA_HISTORY_CHANGED');
            }
        }
        assert(is_array($old['components']) && is_array($new['components']));
        foreach ($old['components'] as $id => $component) {
            $next = $new['components'][$id] ?? null;
            assert(is_array($component));
            SchemaValues::require(valid: is_array($next) && is_int($next['version']) && is_int($component['version']) && $next['version'] >= $component['version'], key: 'components.'.$id, rule: 'SCHEMA_HISTORY_CHANGED');
            SchemaValues::require(valid: $next['dependencies'] === $component['dependencies'], key: 'dependencies.'.$id, rule: 'SCHEMA_HISTORY_CHANGED');
            assert(is_array($component['steps']) && is_array($next['steps']));
            $prefix = array_slice($next['steps'], 0, count($component['steps']));
            SchemaValues::require(valid: SchemaValues::json($prefix) === SchemaValues::json($component['steps']), key: 'steps.'.$id, rule: 'SCHEMA_HISTORY_CHANGED');
            foreach (array_slice($next['steps'], count($component['steps'])) as $step) {
                SchemaValues::require(valid: is_array($step) && is_int($step['version']) && $step['version'] > $component['version'], key: 'steps.'.$id, rule: 'SCHEMA_HISTORY_CHANGED');
            }
            SchemaValues::require(valid: count($next['steps']) === count($component['steps']) || $next['version'] > $component['version'], key: 'version.'.$id, rule: 'SCHEMA_HISTORY_CHANGED');
        }
    }
}
