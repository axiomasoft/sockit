<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ReflectionClass;
use ReflectionNamedType;
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
        foreach (array_keys($this->specifications($schema)) as $entity) {
            SchemaValues::require(valid: $this->changes(schema: $schema, entity: $entity) === [], key: 'models.'.$entity, rule: 'MODEL_BINDING_INVALID');
        }
    }

    /** No file writes or relation invocation. Return exact repairs for a preserved consumer class.
     * @return array<string, mixed> Empty only when binding, casts and required relation signatures agree. */
    public function changes(PanelSchema $schema, string $entity): array
    {
        $expected = $this->specifications($schema)[$entity];
        $methods = new ModelEmitter()->methods($schema)[$entity] ?? [];
        $class = $schema->storage->models[$entity];
        $bindingValid = false;
        $missing = $methods;
        try {
            if (class_exists($class) && is_subclass_of($class, PanelModel::class)) {
                $reflection = new ReflectionClass($class);
                if ($reflection->isInstantiable()) {
                    $model = $reflection->newInstance();
                    $bindingValid = $model::binding() === $expected && $model->getCasts() === $expected['casts']
                        && $model->getKeyType() === $expected['key_type'] && $model->getIncrementing() === $expected['incrementing']
                        && $model->usesTimestamps() === $expected['timestamps'];
                    foreach (array_keys($methods) as $method) {
                        if ($reflection->hasMethod($method)) {
                            $signature = $reflection->getMethod($method);
                            $returns = $signature->getReturnType();
                            $requiredType = str_contains($methods[$method], 'Relations\\BelongsTo') ? BelongsTo::class : HasMany::class;
                            if ($signature->isPublic() && ! $signature->isStatic() && $signature->getNumberOfRequiredParameters() === 0
                                && $returns instanceof ReflectionNamedType && ! $returns->allowsNull() && is_a($returns->getName(), $requiredType, true)) {
                                unset($missing[$method]);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Invalid or unloadable custom PHP is not compatible and is never overwritten.
        }
        if ($bindingValid && $missing === []) {
            return [];
        }

        return ['class' => $class, 'required_binding' => $expected, 'required_casts' => $expected['casts'], 'required_methods' => $missing,
            'binding_valid' => $bindingValid, 'instruction' => 'Update the preserved consumer model manually, keeping your custom code. Set BINDING to required_binding and add the listed relation methods. Retry this command in a fresh Artisan process.'];
    }
}
