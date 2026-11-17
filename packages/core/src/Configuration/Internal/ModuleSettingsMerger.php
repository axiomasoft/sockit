<?php

declare(strict_types=1);

namespace SocKit\Configuration\Internal;

use Packman\Attributes\Internal;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Panels\Exceptions\PanelException;

/**
 * Recursively merges module defaults -> global config -> panel overrides (replacing lists atomically)
 * and validates the final map through the module's {@see ModuleSettingsSchema}.
 */
#[Internal]
final class ModuleSettingsMerger
{
    /**
     * @param  array<string, scalar|array<array-key, mixed>|null>  $global
     * @param  array<string, scalar|array<array-key, mixed>|null>  $panel
     * @return array<string, scalar|array<array-key, mixed>|null>
     */
    public static function compile(
        string $moduleId,
        string $panelId,
        ?ModuleSettingsSchema $schema,
        array $global,
        array $panel,
    ): array {
        if (! $schema instanceof ModuleSettingsSchema) {
            if ($global !== [] || $panel !== []) {
                $badKey = array_key_first($panel) ?? array_key_first($global);

                throw PanelException::invalidConfig(
                    'PANEL_SETTING_INVALID',
                    "Module [{$moduleId}] on panel [{$panelId}] declares no settings schema; unexpected key [{$badKey}].",
                    ['module' => $moduleId, 'panel' => $panelId, 'key' => $badKey],
                );
            }

            return [];
        }

        $defaults = $schema->defaults();
        $merged = self::mergeMaps($defaults, $global);
        $merged = self::mergeMaps($merged, $panel);

        $allowed = array_flip(array_keys($defaults));
        foreach (array_keys($merged) as $key) {
            if (! isset($allowed[$key])) {
                throw PanelException::invalidConfig(
                    'PANEL_SETTING_INVALID',
                    "Unknown setting [{$key}] for module [{$moduleId}] on panel [{$panelId}].",
                    ['module' => $moduleId, 'panel' => $panelId, 'key' => $key],
                );
            }
        }

        $violations = $schema->validate($merged);

        if ($violations !== []) {
            $first = $violations[0];

            throw PanelException::invalidConfig(
                'PANEL_SETTING_INVALID',
                "Invalid setting [{$first['key']}] for module [{$moduleId}] on panel [{$panelId}]: {$first['rule']}.",
                ['module' => $moduleId, 'panel' => $panelId, 'key' => $first['key'], 'rule' => $first['rule']],
            );
        }

        return $merged;
    }

    /**
     * @param  array<string, scalar|array<array-key, mixed>|null>  $base
     * @param  array<string, scalar|array<array-key, mixed>|null>  $override
     * @return array<string, scalar|array<array-key, mixed>|null>
     */
    public static function mergeMaps(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key]) && ! array_is_list($value) && ! array_is_list($result[$key])) {
                /** @var array<string, scalar|array<array-key, mixed>|null> $subBase */
                $subBase = $result[$key];
                /** @var array<string, scalar|array<array-key, mixed>|null> $subOverride */
                $subOverride = $value;
                $result[$key] = self::mergeMaps($subBase, $subOverride);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
