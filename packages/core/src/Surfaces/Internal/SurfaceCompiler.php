<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Internal;

use Illuminate\Contracts\Foundation\Application;
use SocKit\Audience\Exceptions\FloorWidensException;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Modules;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Exceptions\InvalidSurfaceDefinitionException;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class SurfaceCompiler
{
    /** @var array<string, true> */
    private array $providers = [];

    private bool $collecting = true;

    private ?CompiledSurfaceDefinitions $draft = null;

    private ?CompiledSurfaceDefinitions $definitions = null;

    public function add(string $provider): void
    {
        $this->phase();
        $this->providers[$provider] = true;
    }

    public function published(): SurfaceDefinitions
    {
        return $this->definitions ?? throw InvalidSurfaceDefinitionException::at('SURFACE_REGISTRY_PHASE');
    }

    public function compile(Application $app, SocKitConfig $config, Modules $modules, PanelDefinitions $panels): void
    {
        $this->phase();
        $this->collecting = false;
        $providers = array_merge(array_keys($this->providers), $config->surfaces->providers);
        foreach ($modules->declarations() as $declaration) {
            array_push($providers, ...$declaration['surfaces']);
        }
        sort($providers);
        $definitions = $instances = $errors = [];
        foreach ($providers as $provider) {
            if (! SocKitConfig::implementation(value: $provider, contract: SurfaceProvider::class)) {
                $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_PROVIDER_INVALID', provider: $provider)->errors[0];

                continue;
            }
            try {
                $instance = $app->make($provider);
                if (! $instance instanceof SurfaceProvider) {
                    throw InvalidSurfaceDefinitionException::at(code: 'SURFACE_PROVIDER_INVALID', provider: $provider);
                }
                $instances[$provider] = $instance;
                $builder = new Surface($provider);
                $returned = null;
                try {
                    $returned = $instance->surface($builder);
                    $panelId = $returned->panelId();
                    $baseline = $panelId !== null && $panels->has($panelId) ? $panels->get($panelId)->floor : $config->privacy->floor;
                    $surface = $returned->materialize($baseline);
                    $config->privacy->floor->narrowedBy($surface->effectiveFloor);
                } finally {
                    $builder->freeze();
                    if (isset($returned)) {
                        $returned->freeze();
                    }
                }
                $id = $surface->panel->value.'/'.$surface->kind->value;
                if (isset($definitions[$id])) {
                    $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_KIND_DUPLICATE', provider: $provider, panel: $surface->panel->value, key: 'kind')->errors[0];
                }
                if (! $panels->has($surface->panel)) {
                    $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_PANEL_UNKNOWN', provider: $provider, panel: $surface->panel->value, key: 'panel')->errors[0];

                    continue;
                }
                $panel = $panels->get($surface->panel);
                if (! in_array($surface->hostType, $panel->hostTypes, true)) {
                    $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_HOST_MISMATCH', provider: $provider, panel: $panel->id->value, key: 'host')->errors[0];
                }
                $available = [];
                foreach ($panel->modules as $module) {
                    array_push($available, ...$modules->declarations()[$module]['features']);
                }
                foreach ($surface->features as $feature) {
                    if (! in_array($feature, $available, true)) {
                        $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_FEATURE_DISABLED', provider: $provider, panel: $panel->id->value, key: 'features.'.$feature)->errors[0];
                    }
                }
                // Provenance belongs to the registered provider, including replacement builders.
                $definitions[$id] = new SurfaceDefinition(panel: $surface->panel, kind: $surface->kind, hostType: $surface->hostType,
                    label: $surface->label, contourLevel: $surface->contourLevel, effectiveFloor: $surface->effectiveFloor,
                    features: $surface->features, archiveReaders: $surface->archiveReaders, settings: $surface->settings, provider: $provider);
            } catch (InvalidSurfaceDefinitionException $error) {
                array_push($errors, ...$error->errors);
            } catch (FloorWidensException) {
                $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_FLOOR_WIDENS', provider: $provider, key: 'floor')->errors[0];
            } catch (\Throwable) {
                $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_PROVIDER_FAILED', provider: $provider)->errors[0];
            }
        }
        ksort($definitions);
        foreach ($instances as $provider => $instance) {
            try {
                $instance->validate($definitions);
            } catch (InvalidSurfaceDefinitionException $error) {
                array_push($errors, ...$error->errors);
            } catch (\Throwable) {
                $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_VALIDATION_FAILED', provider: $provider, key: 'validate')->errors[0];
            }
        }
        if ($errors !== []) {
            throw new InvalidSurfaceDefinitionException($errors);
        }
        $this->draft = new CompiledSurfaceDefinitions($definitions);
    }

    public function publish(): void
    {
        if ($this->collecting || $this->draft === null || $this->definitions !== null) {
            throw InvalidSurfaceDefinitionException::at('SURFACE_REGISTRY_PHASE');
        }
        $this->definitions = $this->draft;
    }

    private function phase(): void
    {
        if (! $this->collecting) {
            throw InvalidSurfaceDefinitionException::at('SURFACE_REGISTRY_PHASE');
        }
    }
}
