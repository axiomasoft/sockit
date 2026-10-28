<?php

declare(strict_types=1);

namespace SocKit\Schema;

use ReflectionClass;
use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Schema\Internal\SchemaValues;

/** Explicit persistence preflight; boot-time data compilation never autoloads consumer models. */
#[Spi(kind: SpiKind::Call)]
final class ModelBindings
{
    /** @return array<string, array<string, mixed>> */
    public function specifications(PanelSchema $schema): array
    {
        $draft = $schema->jsonSerialize();
        $entities = SchemaValues::map($draft['entities']);
        $bindings = [];
        foreach ($entities as $entity => $value) {
            $spec = SchemaValues::map($value);
            $model = SchemaValues::map($spec['model']);
            $casts = [];
            foreach (SchemaValues::map($spec['columns']) as $column => $definition) {
                $type = SchemaValues::map($definition)['type'];
                $casts[$column] = match ($type) {
                    'json' => 'array', 'timestamp' => 'immutable_datetime', 'boolean' => 'boolean', 'integer', 'bigint' => 'integer', default => 'string',
                };
            }
            $casts = [...$casts, ...SchemaValues::map($model['casts'])];
            ksort($casts);
            $bindings[$entity] = ['panel' => $schema->panel, 'entity' => $entity, 'connection' => $schema->storage->connection,
                'schema' => $schema->storage->schema, 'table' => $schema->storage->tables[$entity],
                'primary' => $model['primary'], 'key_type' => $model['keyType'], 'incrementing' => $model['incrementing'],
                'timestamps' => $model['timestamps'], 'key_policy' => $spec['key_policy'], 'casts' => $casts];
        }

        return $bindings;
    }

    /** Validate the whole map before install/use, including custom classes. No database queries. */
    public function validate(PanelSchema $schema): void
    {
        foreach ($this->specifications($schema) as $entity => $expected) {
            $class = $schema->storage->models[$entity];
            SchemaValues::require(valid: class_exists($class) && is_subclass_of($class, PanelModel::class), key: 'models.'.$entity, rule: 'MODEL_BINDING_INVALID');
            $reflection = new ReflectionClass($class);
            SchemaValues::require(valid: $reflection->isInstantiable() && ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0, key: 'models.'.$entity, rule: 'MODEL_BINDING_INVALID');
            $model = $reflection->newInstance();
            SchemaValues::require(valid: $model::binding() === $expected && $model->getCasts() === $expected['casts']
                && $model->getKeyType() === $expected['key_type'] && $model->getIncrementing() === $expected['incrementing']
                && $model->usesTimestamps() === $expected['timestamps'], key: 'models.'.$entity, rule: 'MODEL_BINDING_INVALID');
        }
    }
}
