<?php

declare(strict_types=1);

namespace SocKit\Panels\Internal;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Audience\Exceptions\FloorWidensException;
use SocKit\Configuration\Data\ModulesConfig;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Modules\Modules;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\Panel;
use SocKit\Panels\PanelDefinition;
use SocKit\Panels\PanelProvider;

final class PanelGraphCompiler
{
    /** @var array<string, true> */
    private array $providers = [];

    private bool $collecting = true;

    /** @var array<string, PanelDefinition>|null */
    private ?array $definitions = null;

    /** @var array<string, PanelDefinition>|null */
    private ?array $draft = null;

    public function add(string $provider): void
    {
        $this->phase();
        $this->providers[$provider] = true;
    }

    /** @return array<string, PanelDefinition> */
    public function published(): array
    {
        if ($this->definitions === null) {
            $this->fail(panel: '', provider: '', key: 'panels', rule: 'PANEL_REGISTRY_PHASE');
        }

        return $this->definitions;
    }

    /** @return array<string, PanelDefinition> */
    public function compile(Application $app, SocKitConfig $config, Modules $modules): array
    {
        $this->phase();
        $this->collecting = false;
        $providers = array_merge(array_keys($this->providers), $config->panels->providers);
        // Repeated FQCN declarations are errors rather than silently hiding duplicate IDs.
        sort($providers);
        $definitions = $instances = $storage = [];
        foreach ($providers as $provider) {
            if (! SocKitConfig::implementation(value: $provider, contract: PanelProvider::class)) {
                $this->fail(panel: '', provider: $provider, key: 'panels', rule: 'PANEL_PROVIDER_INVALID');
            }
            $instance = $app->make($provider);
            if (! $instance instanceof PanelProvider) {
                $this->fail(panel: '', provider: $provider, key: 'panels', rule: 'PANEL_PROVIDER_INVALID');
            }
            try {
                $panel = $instance->panel(new Panel(floor: $config->privacy->floor, provider: $provider))->materialize();
            } catch (InvalidPanelException $error) {
                throw new InvalidPanelException(message: $error->getMessage(), detail: array_replace($error->detail(), ['provider' => $provider]), previous: $error);
            }
            $id = $panel->id->value;
            if (isset($definitions[$id])) {
                $this->fail(panel: $id, provider: $provider, key: 'id', rule: 'PANEL_ID_DUPLICATE');
            }
            try {
                $config->privacy->floor->narrowedBy($panel->floor);
            } catch (FloorWidensException $error) {
                $this->fail(panel: $id, provider: $provider, key: 'floor', rule: 'PANEL_FLOOR_WIDENS', previous: $error);
            }
            $settings = [];
            foreach ($panel->moduleSettings as $module => $values) {
                if (! in_array($module, $panel->modules, true)) {
                    $this->fail(panel: $id, provider: $provider, key: 'moduleSettings.'.$module, rule: 'PANEL_SETTING_INVALID');
                }
            }
            foreach ($panel->modules as $module) {
                if (! isset($modules->manifests()[$module])) {
                    $this->fail(panel: $id, provider: $provider, key: 'modules.'.$module, rule: 'PANEL_MODULE_NOT_ENABLED');
                }
                $fqcn = $modules->declarations()[$module]['settings'];
                $schema = $fqcn === null ? null : $app->make($fqcn);
                if ($schema !== null && ! $schema instanceof ModuleSettingsSchema) {
                    $this->fail(panel: $id, provider: $provider, key: 'moduleSettings.'.$module, rule: 'PANEL_SETTING_INVALID');
                }
                try {
                    $merged = ModulesConfig::settingsMap(value: ModulesConfig::merge(defaults: $modules->settings()[$module], overrides: $panel->moduleSettings[$module] ?? []), key: 'moduleSettings.'.$module);
                    $errors = $schema?->validate($merged) ?? [];
                    if (($schema === null && $merged !== []) || $errors !== []) {
                        $this->fail(panel: $id, provider: $provider, key: 'moduleSettings.'.$module.'.'.($errors[0]['key'] ?? ''), rule: 'PANEL_SETTING_INVALID');
                    }
                    $settings[$module] = $merged;
                } catch (InvalidConfigurationException $error) {
                    $this->fail(panel: $id, provider: $provider, key: 'moduleSettings.'.$module, rule: 'PANEL_SETTING_INVALID', previous: $error);
                }
            }
            foreach ($panel->storage->tables as $key => $table) {
                // Declared identities only. Connection aliases and driver prefixes require P3.
                $identity = json_encode([$panel->storage->connection, $panel->storage->schema, $table], JSON_THROW_ON_ERROR);
                if (isset($storage[$identity])) {
                    $this->fail(panel: $id, provider: $provider, key: 'tables.'.$key, rule: 'PANEL_STORAGE_COLLISION');
                }
                $storage[$identity] = true;
            }
            $definitions[$id] = new PanelDefinition(id: $panel->id, hostTypes: $panel->hostTypes, storage: $panel->storage, modules: $panel->modules, moduleSettings: $settings, floor: $panel->floor, provider: $provider);
            $instances[] = $instance;
        }
        ksort($definitions);
        foreach ($instances as $instance) {
            $instance->validate($definitions);
        }

        return $this->draft = $definitions;
    }

    /** @param array<string, PanelDefinition> $draft */
    public function publish(array $draft): void
    {
        if ($this->collecting || $this->draft !== $draft || $this->definitions !== null) {
            $this->fail(panel: '', provider: '', key: 'panels', rule: 'PANEL_REGISTRY_PHASE');
        }
        $this->definitions = $draft;
    }

    private function phase(): void
    {
        if (! $this->collecting) {
            $this->fail(panel: '', provider: '', key: 'panels', rule: 'PANEL_REGISTRY_PHASE');
        }
    }

    private function fail(string $panel, string $provider, string $key, string $rule, ?\Throwable $previous = null): never
    {
        throw new InvalidPanelException(message: 'Invalid SocKit panel graph', detail: ['panel' => $panel, 'provider' => $provider, 'key' => $key, 'rule' => $rule], previous: $previous);
    }
}
