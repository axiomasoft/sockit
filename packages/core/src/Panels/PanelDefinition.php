<?php

declare(strict_types=1);

namespace SocKit\Panels;

use Packman\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Modules\ModuleManifest;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Internal\PanelValues;

#[Api(since: '1.0')]
final readonly class PanelDefinition
{
    /** @var list<string> */
    public array $hostTypes;

    /** @var list<string> */
    public array $modules;

    /** @var array<string, array<string, scalar|array<array-key, mixed>|null>> */
    public array $moduleSettings;

    public PrivacyFloor $floor;

    /** @param list<string> $hostTypes
     * @param list<string> $modules
     * @param array<string, array<string, scalar|array<array-key, mixed>|null>> $moduleSettings */
    public function __construct(public PanelId $id, array $hostTypes, public PanelStorage $storage, array $modules, array $moduleSettings, PrivacyFloor $floor, public string $provider)
    {
        PanelValues::require(valid: $hostTypes !== [] && count(array_unique($hostTypes)) === count($hostTypes), key: 'hostTypes', rule: 'PANEL_HOST_INVALID');
        $hosts = [];
        foreach ($hostTypes as $host) {
            PanelValues::require(valid: preg_match('~^[a-z][a-z0-9_.-]{0,95}$~D', $host) === 1, key: 'hostTypes', rule: 'PANEL_HOST_INVALID');
            $hosts[] = $host;
        }
        PanelValues::require(valid: count(array_unique($modules)) === count($modules), key: 'modules', rule: 'PANEL_MODULE_NOT_ENABLED');
        $selected = [];
        foreach ($modules as $module) {
            PanelValues::require(valid: preg_match(ModuleManifest::ID_PATTERN, $module) === 1, key: 'modules', rule: 'PANEL_MODULE_NOT_ENABLED');
            $selected[] = $module;
        }
        $settings = [];
        foreach ($moduleSettings as $module => $values) {
            PanelValues::require(valid: preg_match(ModuleManifest::ID_PATTERN, $module) === 1, key: 'moduleSettings', rule: 'PANEL_SETTING_INVALID');
            $map = [];
            foreach (PanelValues::copy(values: $values, key: 'moduleSettings.'.$module) as $key => $value) {
                PanelValues::require(valid: is_string($key), key: 'moduleSettings.'.$module, rule: 'PANEL_SETTING_INVALID');
                $map[$key] = $value;
            }
            $settings[$module] = $map;
        }
        sort($hosts);
        sort($selected);
        ksort($settings);
        $this->hostTypes = $hosts;
        $this->modules = $selected;
        $this->moduleSettings = $settings;
        $scopes = $levels = [];
        foreach ($floor->allowedScopes as $scope) {
            $scopes[] = $scope;
        }
        foreach ($floor->contourLevels as $level) {
            $levels[] = $level;
        }
        $this->floor = new PrivacyFloor(allowedScopes: $scopes, defaultScope: $floor->defaultScope, maxScope: $floor->maxScope,
            minorDefaultScope: $floor->minorDefaultScope, minorMaxScope: $floor->minorMaxScope, contourLevels: $levels);
    }
}
