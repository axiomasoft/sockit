<?php

declare(strict_types=1);

namespace SocKit\Schema;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Schema\Internal\SchemaValues;

/** Returns consumer-owned sources without writing, overwriting or autoloading files. */
#[Spi(kind: SpiKind::Call)]
final class ModelEmitter
{
    /** @return array<string, string> FQCN => PHP source */
    public function render(PanelSchema $schema): array
    {
        $stub = file_get_contents(dirname(__DIR__, 2).'/resources/stubs/models/model.php.stub');
        SchemaValues::require(valid: is_string($stub), key: 'stub', rule: 'MODEL_STUB_UNAVAILABLE');
        $sources = $relations = [];
        $entities = SchemaValues::map($schema->jsonSerialize()['entities']);
        foreach ($entities as $entity => $value) {
            $spec = SchemaValues::map($value);
            foreach (SchemaValues::map($spec['foreign_keys']) as $name => $value) {
                $fk = SchemaValues::map($value);
                $target = SchemaValues::text($fk['entity']);
                $columns = $fk['columns'];
                $references = $fk['references'];
                assert(is_array($columns) && is_array($references));
                // Eloquent relations only support single-column keys. Never silently truncate composites.
                SchemaValues::require(valid: count($columns) === 1 && count($references) === 1, key: $entity.'.'.$name, rule: 'MODEL_RELATION_COMPOSITE_UNSUPPORTED');
                $forward = $this->method($name);
                SchemaValues::require(valid: ! isset($relations[$entity][strtolower($forward)]), key: $entity.'.'.$name, rule: 'MODEL_RELATION_COLLISION');
                $relations[$entity][strtolower($forward)] = $this->relation(method: $forward, kind: 'belongsTo', class: $schema->storage->models[$target], foreign: SchemaValues::text($columns[0]), local: SchemaValues::text($references[0]));
                $inverse = $this->method($entity.'_'.$name);
                SchemaValues::require(valid: ! isset($relations[$target][strtolower($inverse)]), key: $target.'.'.$inverse, rule: 'MODEL_RELATION_COLLISION');
                $relations[$target][strtolower($inverse)] = $this->relation(method: $inverse, kind: 'hasMany', class: $schema->storage->models[$entity], foreign: SchemaValues::text($columns[0]), local: SchemaValues::text($references[0]));
            }
        }
        foreach (new ModelBindings()->specifications($schema) as $entity => $binding) {
            $class = $schema->storage->models[$entity];
            $position = strrpos($class, '\\');
            SchemaValues::require(valid: $position !== false, key: 'models.'.$entity, rule: 'MODEL_NAMESPACE_REQUIRED');
            $methods = $relations[$entity] ?? [];
            ksort($methods);
            $sources[$class] = strtr($stub, ['{{NAMESPACE}}' => substr($class, 0, $position), '{{CLASS}}' => substr($class, $position + 1),
                '{{PANEL}}' => $schema->panel, '{{ENTITY}}' => $entity, '{{BINDING}}' => var_export($binding, true), '{{RELATIONS}}' => implode("\n", $methods)]);
        }

        return $sources;
    }

    private function method(string $name): string
    {
        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
        SchemaValues::require(valid: ! method_exists(PanelModel::class, $method), key: $name, rule: 'MODEL_RELATION_COLLISION');

        return $method;
    }

    private function relation(string $method, string $kind, string $class, string $foreign, string $local): string
    {
        $type = $kind === 'belongsTo' ? 'BelongsTo' : 'HasMany';

        return "\n    /** @return \\Illuminate\\Database\\Eloquent\\Relations\\{$type}<\\{$class}, \$this> */\n"
            ."    public function {$method}(): \\Illuminate\\Database\\Eloquent\\Relations\\{$type}\n    {\n"
            .'        return $this->'.$kind.'(related: \\'.$class.'::class, foreignKey: '.var_export($foreign, true).', '.($kind === 'belongsTo' ? 'ownerKey' : 'localKey').': '.var_export($local, true).");\n    }\n";
    }
}
