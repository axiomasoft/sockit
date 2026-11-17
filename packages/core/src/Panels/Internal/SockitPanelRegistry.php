<?php

declare(strict_types=1);

namespace SocKit\Panels\Internal;

use Closure;
use Override;
use Packman\Attributes\Internal;
use SocKit\Configuration\Internal\ModuleSettingsMerger;
use SocKit\Configuration\Internal\SockitModules;
use SocKit\Configuration\SockitOptions;
use SocKit\Database\StorageCollisionChecker;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelDefinition;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\FrozenDefinitionException;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\SockitPanelBuilder;

/**
 * Compile-time deterministic panel registry supporting atomic compilation, module settings
 * compilation, cross-panel validation, and physical storage collision checking.
 */
#[Internal]
final class SockitPanelRegistry implements PanelDefinitions
{
    /** @var array<string, PanelDefinition> */
    private array $panels = [];

    private bool $frozen = false;

    /**
     * @param  list<class-string<PanelProvider>|PanelProvider>  $providers
     * @param  list<string>  $activeModules
     * @param  array<string, ModuleSettingsSchema>  $moduleSchemas
     * @param  array<string, array<string, mixed>>  $databaseConnections
     * @param  Closure(class-string<PanelProvider>): PanelProvider  $providerResolver
     */
    public function compileAndFreeze(
        array $providers,
        SockitOptions $options,
        array $activeModules,
        array $moduleSchemas,
        array $databaseConnections,
        string $defaultConnection,
        Closure $providerResolver,
    ): void {
        if ($this->frozen) {
            throw FrozenDefinitionException::forRegistry(self::class);
        }

        $activeSet = array_flip(SockitModules::normalizeList($activeModules));
        $compiled = [];
        $resolvedProviders = [];

        foreach ($providers as $entry) {
            $provider = $entry instanceof PanelProvider ? $entry : $providerResolver($entry);
            $raw = $provider->panel(new SockitPanelBuilder($provider::class))->materialize($options->privacyFloor);

            if (isset($compiled[$raw->id->value])) {
                $providerClass = $provider::class;
                throw PanelException::invalidConfig(
                    'PANEL_DUPLICATE_ID',
                    "Duplicate panel id [{$raw->id->value}] declared by [{$providerClass}].",
                    ['panel' => $raw->id->value],
                );
            }

            $storageConnection = $raw->storage->connection !== '' ? $raw->storage->connection : ($options->connection ?? '');

            if ($storageConnection !== '' && ! isset($databaseConnections[$storageConnection])) {
                throw PanelException::invalidConfig(
                    'PANEL_CONNECTION_UNKNOWN',
                    "Panel [{$raw->id->value}] references unknown database connection [{$storageConnection}].",
                    ['panel' => $raw->id->value, 'connection' => $storageConnection],
                );
            }

            $normalizedModules = [];
            foreach ($raw->modules as $moduleId) {
                $canonical = SockitModules::normalize($moduleId);
                if (! isset($activeSet[$canonical])) {
                    throw PanelException::invalidConfig(
                        'PANEL_MODULE_INACTIVE',
                        "Panel [{$raw->id->value}] requires inactive module [{$canonical}].",
                        ['panel' => $raw->id->value, 'module' => $canonical],
                    );
                }
                $normalizedModules[] = $canonical;
            }
            $normalizedModules = array_values(array_unique($normalizedModules));
            sort($normalizedModules, SORT_STRING);

            $panelModuleSet = array_flip($normalizedModules);
            foreach (array_keys($raw->moduleSettings) as $settingModule) {
                $canonical = SockitModules::normalize($settingModule);
                if (! isset($panelModuleSet[$canonical])) {
                    throw PanelException::invalidConfig(
                        'PANEL_MODULE_UNKNOWN',
                        "Panel [{$raw->id->value}] declares settings for unattached module [{$canonical}].",
                        ['panel' => $raw->id->value, 'module' => $canonical],
                    );
                }
            }

            $compiledModuleSettings = [];
            foreach ($normalizedModules as $canonicalModule) {
                $globalSettings = $options->moduleSettings[$canonicalModule] ?? [];
                $panelSettings = $raw->moduleSettings[$canonicalModule] ?? [];
                $compiledModuleSettings[$canonicalModule] = ModuleSettingsMerger::compile(
                    moduleId: $canonicalModule,
                    panelId: $raw->id->value,
                    schema: $moduleSchemas[$canonicalModule] ?? null,
                    global: $globalSettings,
                    panel: $panelSettings,
                );
            }

            $compiled[$raw->id->value] = new PanelDefinition(
                id: $raw->id,
                hostTypes: $raw->hostTypes,
                storage: $raw->storage,
                modules: $normalizedModules,
                moduleSettings: $compiledModuleSettings,
                floor: $raw->floor,
                provider: $raw->provider,
            );
            $resolvedProviders[$raw->id->value] = $provider;
        }

        ksort($compiled, SORT_STRING);
        ksort($resolvedProviders, SORT_STRING);

        foreach ($resolvedProviders as $provider) {
            $provider->validate($compiled);
        }

        (new StorageCollisionChecker)->assertNoCollisions(
            panels: $compiled,
            databaseConnections: $databaseConnections,
            defaultConnection: $defaultConnection,
            moduleTables: SockitModules::tablesByModule(),
        );

        $this->panels = $compiled;
        $this->frozen = true;
    }

    #[Override]
    public function get(PanelId $id): PanelDefinition
    {
        $this->assertFrozen();

        if (! isset($this->panels[$id->value])) {
            throw PanelException::notFound($id->value);
        }

        return $this->panels[$id->value];
    }

    #[Override]
    public function has(PanelId $id): bool
    {
        $this->assertFrozen();

        return isset($this->panels[$id->value]);
    }

    /** @return array<string, PanelDefinition> */
    #[Override]
    public function all(): array
    {
        $this->assertFrozen();

        return $this->panels;
    }

    #[Override]
    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    private function assertFrozen(): void
    {
        if (! $this->frozen) {
            throw FrozenDefinitionException::forRegistry(self::class);
        }
    }
}
