<?php

declare(strict_types=1);

namespace SocKit\Modules\Internal;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Configuration\Data\ModulesConfig;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Contracts\Module;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\ModuleManifest;
use SocKit\Modules\ModuleRegistrar;

final class ModuleGraphCompiler
{
    /** @var array<string, true> */
    private array $offered = [];

    private bool $collecting = true;

    private ?CompiledModuleGraph $graph = null;

    private ?CompiledModuleGraph $draft = null;

    public function add(string $module): void
    {
        if (! $this->collecting) {
            throw new InvalidModuleException([['module' => $module, 'key' => 'modules', 'rule' => 'MODULE_REGISTRATION_CLOSED']]);
        }
        $this->offered[$module] = true;
    }

    public function published(): CompiledModuleGraph
    {
        if ($this->graph === null) {
            throw new InvalidModuleException([['module' => '', 'key' => 'modules', 'rule' => 'MODULE_GRAPH_NOT_READY']]);
        }

        return $this->graph;
    }

    public function compile(Application $app, SocKitConfig $config): CompiledModuleGraph
    {
        if (! $this->collecting) {
            throw new InvalidModuleException([['module' => '', 'key' => 'modules', 'rule' => 'MODULE_REGISTRATION_CLOSED']]);
        }
        $this->collecting = false;
        $errors = [];
        $modules = [];
        $manifests = [];
        $offered = array_keys($this->offered);
        sort($offered);
        foreach ($offered as $fqcn) {
            if (! SocKitConfig::implementation(value: $fqcn, contract: Module::class)) {
                $errors[] = ['module' => $fqcn, 'key' => 'modules', 'rule' => 'MODULE_IMPLEMENTATION_INVALID'];

                continue;
            }
            $module = $app->make($fqcn);
            if (! $module instanceof Module) {
                $errors[] = ['module' => $fqcn, 'key' => 'modules', 'rule' => 'MODULE_IMPLEMENTATION_INVALID'];

                continue;
            }
            $manifest = $module->manifest();
            foreach ($this->manifestErrors($manifest) as $rule) {
                $errors[] = ['module' => $manifest->id, 'key' => 'manifest', 'rule' => $rule];
            }
            if (isset($manifests[$manifest->id])) {
                $errors[] = ['module' => $manifest->id, 'key' => 'manifest.id', 'rule' => 'MODULE_DUPLICATE_ID'];
            }
            $manifests[$manifest->id] = $manifest;
            $modules[$manifest->id] = $module;
        }
        foreach ($config->modules->enabled as $id) {
            if (! isset($modules[$id])) {
                $errors[] = ['module' => $id, 'key' => 'modules.enabled', 'rule' => 'MODULE_LISTED_NOT_OFFERED'];
            }
        }
        if ($errors !== []) {
            throw new InvalidModuleException($errors);
        }
        ksort($modules);
        $active = $declarations = $settings = $features = [];
        foreach ($modules as $id => $module) {
            if (! in_array($id, $config->modules->enabled, true)) {
                continue;
            }
            $draft = new ModuleRegistrar($id);
            try {
                $module->register($draft);
                $declaration = $draft->declarations();
                foreach ($declaration['features'] as $feature) {
                    if (isset($features[$feature])) {
                        $errors[] = ['module' => $id, 'key' => 'features.'.$feature, 'rule' => 'MODULE_FEATURE_DUPLICATE'];
                    }
                    $features[$feature] = $id;
                }
                $schema = $declaration['settings'] !== null ? $app->make($declaration['settings']) : null;
                if ($schema !== null && ! $schema instanceof ModuleSettingsSchema) {
                    throw new InvalidModuleException([['module' => $id, 'key' => 'settings', 'rule' => 'MODULE_SETTINGS_SCHEMA_INVALID']]);
                }
                $global = $config->modules->settings[$id] ?? [];
                if ($schema === null && $global !== []) {
                    $errors[] = ['module' => $id, 'key' => 'settings', 'rule' => 'MODULE_SETTINGS_INVALID'];
                }
                $defaults = $schema?->defaults() ?? [];
                $defaults = ModulesConfig::settingsMap(value: $defaults, key: 'settings');
                $merged = ModulesConfig::settingsMap(value: ModulesConfig::merge(defaults: $defaults, overrides: $global), key: 'settings');
                foreach ($schema?->validate($merged) ?? [] as $error) {
                    $errors[] = ['module' => $id, 'key' => $error['key'], 'rule' => $error['rule']];
                }
                $settings[$id] = $merged;
                $declarations[$id] = $declaration;
                $active[$id] = $manifests[$id];
            } catch (InvalidModuleException $error) {
                array_push($errors, ...$error->errors);
            } catch (InvalidConfigurationException $error) {
                $errors[] = ['module' => $id, 'key' => (string) $error->detail()['key'], 'rule' => 'MODULE_SETTINGS_INVALID'];
            }
        }
        if ($errors !== []) {
            throw new InvalidModuleException($errors);
        }

        return $this->draft = new CompiledModuleGraph(manifests: $active, declarations: $declarations, settings: $settings);
    }

    public function publish(CompiledModuleGraph $draft): void
    {
        if ($this->collecting || $this->draft !== $draft || $this->graph !== null) {
            throw new InvalidModuleException([['module' => '', 'key' => 'modules', 'rule' => 'MODULE_REGISTRATION_CLOSED']]);
        }
        $this->graph = $draft;
    }

    /** @return list<string> */
    private function manifestErrors(ModuleManifest $manifest): array
    {
        $errors = [];
        if (preg_match(ModuleManifest::ID_PATTERN, $manifest->id) !== 1) {
            $errors[] = 'MODULE_INVALID_ID';
        }
        $root = $manifest->resourceRoot;
        if ($root !== null && (! str_starts_with($root, '/') || str_contains($root, "\0") || in_array('..', explode('/', $root), true))) {
            $errors[] = 'MODULE_INVALID_RESOURCE_ROOT';
        }
        if (! array_is_list($manifest->databases) || array_diff($manifest->databases, ModuleManifest::DATABASES) !== []) {
            $errors[] = 'MODULE_INVALID_DATABASE';
        }

        return $errors;
    }
}
