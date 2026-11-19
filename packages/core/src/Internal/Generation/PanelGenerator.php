<?php

declare(strict_types=1);

namespace SocKit\Internal\Generation;

use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;
use SocKit\Modules\Modules;
use SocKit\Panels\Panel;
use SocKit\Panels\PanelDefinition;
use SocKit\Panels\PanelDefinitions;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\ModelEmitter;
use SocKit\Schema\PanelSchemaCompiler;
use SocKit\Schema\StoragePreflight;

#[Internal]
final readonly class PanelGenerator
{
    public function __construct(private Modules $modules, private PanelDefinitions $panels, private Repository $config) {}

    /** @param list<string> $modules */
    public function plan(string $root, string $name, string $id, string $host, array $modules, string $prefix, string $connection, string $schema): GenerationPlan
    {
        $root = realpath($root);
        GenerationFailure::require(valid: $root !== false, rule: 'GENERATION_ROOT_INVALID');
        GenerationFailure::require(valid: preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) === 1, rule: 'GENERATION_NAME_INVALID');
        GenerationFailure::require(valid: $id !== '', rule: 'GENERATION_ID_REQUIRED: use --id=acme.article (stable panel ID)');
        GenerationFailure::require(valid: count(array_unique($modules)) === count($modules), rule: 'GENERATION_MODULE_DUPLICATE');
        foreach ($modules as $module) {
            GenerationFailure::require(valid: isset($this->modules->manifests()[$module]), rule: 'GENERATION_MODULE_NOT_ENABLED: install, offer and enable the selected module first');
        }
        $mapping = GenerationPaths::mapping($root);
        $namespace = $mapping['namespace'];
        $directory = $mapping['directory'];
        $provider = $namespace.'Panels\\'.$name.'PanelProvider';
        $compiler = new PanelSchemaCompiler;
        $components = $this->modules->schemaComponents();
        $entities = $compiler->entities(selected: $modules, components: $components);
        $builder = (new Panel)->id($id)->hostTypes($host)->modules(...$modules)->prefix($prefix)->connection($connection)->schema($schema);
        $base = $builder->materialize(entities: $entities);
        $draft = $compiler->compile(panel: $base, components: $components);
        $models = [];
        foreach ($draft->storage->models as $entity => $model) {
            $models[$entity] = $namespace.'Models\\SocKit\\'.$name.'\\'.substr($model, (int) strrpos($model, '\\') + 1);
        }
        $definition = new PanelDefinition(id: $base->id, hostTypes: $base->hostTypes, modules: $base->modules, moduleSettings: $base->moduleSettings, floor: $base->floor, provider: $provider,
            storage: new PanelStorage(connection: $connection, schema: $schema, prefix: $prefix, tables: $draft->storage->tables, models: $models));
        $draft = $compiler->compile(panel: $definition, components: $components);
        $schemas = [$draft];
        foreach ($this->panels->all() as $panel) {
            GenerationFailure::require(valid: $panel->id->value !== $id, rule: 'GENERATION_PANEL_EXISTS');
            foreach ($panel->storage->models as $class) {
                GenerationFailure::require(valid: ! in_array(strtolower($class), array_map(strtolower(...), [$provider, ...array_values($models)]), true), rule: 'GENERATION_FQCN_COLLISION');
            }
            $schemas[] = $compiler->compile(panel: $panel, components: $components);
        }
        $connections = $this->config->get('database.connections', []);
        GenerationFailure::require(valid: is_array($connections), rule: 'GENERATION_CONNECTION_INVALID');
        $resolved = [];
        foreach ($connections as $alias => $values) {
            GenerationFailure::require(valid: is_string($alias) && is_array($values), rule: 'GENERATION_CONNECTION_INVALID');
            $resolved[$alias] = GenerationFailure::map($values);
        }
        $tables = StoragePreflight::generation(schemas: $schemas, connections: $resolved, deployments: $this->config->get('sockit.storage.deployments', []));
        $stub = file_get_contents(dirname(__DIR__, 3).'/resources/stubs/panels/provider.php.stub');
        GenerationFailure::require(valid: is_string($stub), rule: 'GENERATION_STUB_UNAVAILABLE');
        $modelMap = "[\n";
        foreach ($models as $entity => $class) {
            $modelMap .= '                '.var_export($entity, true).' => \\'.$class."::class,\n";
        }
        $modelMap .= '            ]';
        $owners = [];
        foreach (GenerationFailure::map($draft->jsonSerialize()['entities']) as $entity => $specification) {
            $owners[$entity] = GenerationFailure::map($specification)['owner'];
        }
        $providerSource = strtr($stub, ['{{NAMESPACE}}' => $namespace.'Panels', '{{CLASS}}' => $name.'PanelProvider', '{{ID}}' => var_export($id, true),
            '{{HOST}}' => var_export($host, true), '{{CONNECTION}}' => var_export($connection, true), '{{SCHEMA}}' => var_export($schema, true), '{{PREFIX}}' => var_export($prefix, true),
            '{{MODULES}}' => implode(', ', array_map(fn (string $module): string => var_export($module, true), $modules)), '{{MODELS}}' => $modelMap, '{{MODEL_OWNERS}}' => var_export($owners, true)]);
        $sources = $classes = [];
        foreach ([$provider => $providerSource, ...new ModelEmitter()->render($draft)] as $class => $source) {
            $path = $directory.'/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php';
            GenerationPaths::check(root: $root, relative: $path);
            \PhpToken::tokenize($source, TOKEN_PARSE);
            $sources[$path] = $source;
            $classes[$path] = $class;
        }
        GenerationPaths::classes(root: $root, directory: $directory, classes: array_values($classes));

        return new GenerationPlan(root: $root, sources: $sources, manifest: ['panel' => $id, 'provider' => $provider, 'files' => $classes,
            'tables' => $tables[$id], 'models' => $models, 'components' => $draft->jsonSerialize()['components'], 'connection' => $connection, 'schema' => $schema,
            'registration' => "In config/sockit.php append \\{$provider}::class to the 'panels' array. Keep the selected modules installed, offered and enabled.\n"
                ."Example: 'panels' => [\\{$provider}::class],\nNo migration was created. Run the explicit panel migration generator separately when available."]);
    }
}
