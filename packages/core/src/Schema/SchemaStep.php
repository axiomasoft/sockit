<?php

declare(strict_types=1);

namespace SocKit\Schema;

use SocKit\Attributes\Api;
use SocKit\Schema\Internal\SchemaValues;

#[Api(since: '1.0')]
final readonly class SchemaStep
{
    /** @var array<string, SchemaColumn> */
    public array $columns;

    /** @var array<string, SchemaIndex> */
    public array $indexes;

    /** @var array<string, SchemaForeignKey> */
    public array $foreignKeys;

    /** @var array<string, SchemaCheck> */
    public array $checks;

    /** A model creates an entity. A null model adds columns/keys to the same owner's entity.
     * @param array<string, SchemaColumn> $columns
     * @param array<string, SchemaIndex> $indexes
     * @param array<string, SchemaForeignKey> $foreignKeys
     * @param array<string, SchemaCheck> $checks */
    public function __construct(public string $id, public int $version, public string $entity, array $columns, public ?SchemaModel $model = null, array $indexes = [], array $foreignKeys = [], array $checks = [])
    {
        SchemaValues::require(valid: SchemaValues::identifier($id) && SchemaValues::identifier($entity) && $version >= 1, key: 'step');
        foreach ([$columns, $indexes, $foreignKeys, $checks] as $map) {
            foreach (array_keys($map) as $key) {
                SchemaValues::require(valid: SchemaValues::identifier($key), key: 'step.'.$key);
            }
        }
        $copy = [];
        foreach ($columns as $key => $column) {
            $copy[$key] = $column;
        }
        ksort($copy);
        $this->columns = $copy;
        $copy = [];
        foreach ($indexes as $key => $index) {
            $copy[$key] = $index;
        }
        ksort($copy);
        $this->indexes = $copy;
        $copy = [];
        foreach ($foreignKeys as $key => $foreignKey) {
            $copy[$key] = $foreignKey;
        }
        ksort($copy);
        $this->foreignKeys = $copy;
        $copy = [];
        foreach ($checks as $key => $check) {
            $copy[$key] = $check;
        }
        ksort($copy);
        $this->checks = $copy;
        SchemaValues::require(valid: $model !== null || $columns !== [] || $indexes !== [] || $foreignKeys !== [] || $checks !== [], key: 'step.empty');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'entity' => $this->entity,
            'action' => $this->model === null ? 'add' : 'create',
            'columns' => array_map(fn (SchemaColumn $column): array => $column->toArray(), $this->columns),
            'model' => $this->model === null ? null : get_object_vars($this->model),
            'indexes' => array_map(fn (SchemaIndex $index): array => get_object_vars($index), $this->indexes),
            'checks' => array_map(fn (SchemaCheck $check): array => get_object_vars($check), $this->checks),
            'foreign_keys' => array_map(fn (SchemaForeignKey $key): array => get_object_vars($key), $this->foreignKeys)];
    }

    public function digest(): string
    {
        return hash('sha256', SchemaValues::json($this->toArray()));
    }
}
