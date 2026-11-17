<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Internal;

use Closure;
use Override;
use Packman\Attributes\Internal;
use SocKit\Configuration\Internal\SockitModules;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\FrozenDefinitionException;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Exceptions\SurfaceException;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

/**
 * Compile-time deterministic surface registry validated against frozen panel definitions.
 */
#[Internal]
final class SurfaceRegistry implements SurfaceDefinitions
{
    /** @var array<string, SurfaceDefinition> */
    private array $surfaces = [];

    private bool $frozen = false;

    /**
     * @param  list<class-string<SurfaceProvider>|SurfaceProvider>  $providers
     * @param  Closure(class-string<SurfaceProvider>): SurfaceProvider  $providerResolver
     */
    public function compileAndFreeze(
        array $providers,
        PanelDefinitions $panels,
        Closure $providerResolver,
    ): void {
        if ($this->frozen) {
            throw FrozenDefinitionException::forRegistry(self::class);
        }

        if (! $panels->isFrozen()) {
            throw FrozenDefinitionException::forRegistry(PanelDefinitions::class);
        }

        $compiled = [];
        $resolvedProviders = [];

        foreach ($providers as $entry) {
            $provider = $entry instanceof SurfaceProvider ? $entry : $providerResolver($entry);
            $surfaceBuilder = new Surface($provider::class);
            $built = $provider->surface($surfaceBuilder);

            $definition = $this->materializeWithPanelFloor($built, $panels);
            $key = $definition->key();

            if (isset($compiled[$key])) {
                $providerClass = $provider::class;
                throw SurfaceException::invalidDefinition(
                    'SURFACE_DUPLICATE_KIND',
                    "Duplicate surface definition [{$key}] declared by [{$providerClass}].",
                    ['surface' => $key],
                );
            }

            $compiled[$key] = $definition;
            $resolvedProviders[$key] = $provider;
        }

        ksort($compiled, SORT_STRING);
        ksort($resolvedProviders, SORT_STRING);

        foreach ($resolvedProviders as $provider) {
            $provider->validate($compiled);
        }

        $this->surfaces = $compiled;
        $this->frozen = true;
    }

    #[Override]
    public function get(PanelId $panel, SurfaceKindId $kind): SurfaceDefinition
    {
        $this->assertFrozen();
        $key = $panel->value.'/'.$kind->value;

        if (! isset($this->surfaces[$key])) {
            throw SurfaceException::notFound($key);
        }

        return $this->surfaces[$key];
    }

    #[Override]
    public function has(PanelId $panel, SurfaceKindId $kind): bool
    {
        $this->assertFrozen();

        return isset($this->surfaces[$panel->value.'/'.$kind->value]);
    }

    /** @return array<string, SurfaceDefinition> */
    #[Override]
    public function all(): array
    {
        $this->assertFrozen();

        return $this->surfaces;
    }

    #[Override]
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    private function materializeWithPanelFloor(Surface $builder, PanelDefinitions $panels): SurfaceDefinition
    {
        $panelMatch = null;
        $probeException = null;

        foreach ($panels->all() as $panelDefinition) {
            try {
                $candidate = (clone $builder)->materialize($panelDefinition->floor);
                if (! $candidate->panel->equals($panelDefinition->id)) {
                    continue;
                }
                $panelMatch = [$candidate, $panelDefinition];

                break;
            } catch (SurfaceException $exception) {
                $probeException = $exception;

                break;
            }
        }

        if ($panelMatch === null) {
            if ($probeException instanceof SurfaceException) {
                throw $probeException;
            }

            throw SurfaceException::invalidDefinition(
                'SURFACE_PANEL_UNKNOWN',
                'Surface references an unregistered PanelId.',
            );
        }

        [$definition, $panel] = $panelMatch;

        if (! $panel->acceptsHostType($definition->hostType)) {
            throw SurfaceException::invalidDefinition(
                'SURFACE_HOST_MISMATCH',
                "Panel [{$panel->id->value}] does not accept host entity type [{$definition->hostType}].",
                ['panel' => $panel->id->value, 'kind' => $definition->kind->value, 'host_type' => $definition->hostType],
            );
        }

        foreach ($definition->features as $feature) {
            if (SockitModules::isKnownFeature($feature) && ! $panel->hasModule($feature)) {
                throw PanelException::invalidConfig(
                    'SURFACE_FEATURE_UNSUPPORTED',
                    "Surface [{$definition->key()}] enables feature [{$feature}] that is not attached to panel [{$panel->id->value}].",
                    ['panel' => $panel->id->value, 'surface' => $definition->kind->value, 'feature' => $feature],
                );
            }
        }

        return $definition;
    }

    private function assertFrozen(): void
    {
        if (! $this->frozen) {
            throw FrozenDefinitionException::forRegistry(self::class);
        }
    }
}
