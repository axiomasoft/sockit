<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Exceptions\InvalidSurfaceDefinitionException;

#[Api(since: '1.0')]
final readonly class SurfaceDefinition
{
    /** @var list<string> */
    public array $features;

    /** @var array<string, string|int|bool|null> */
    public array $settings;

    public PrivacyFloor $effectiveFloor;

    /** @param list<string> $features
     * @param array<string, string|int|bool|null> $settings */
    public function __construct(public PanelId $panel, public SurfaceKindId $kind, public string $hostType, public string $label,
        public ?string $contourLevel, PrivacyFloor $effectiveFloor, array $features, public bool $archiveReaders,
        array $settings, public string $provider)
    {
        $errors = [];
        if (preg_match('~^[a-z][a-z0-9_.-]{0,95}$~D', $hostType) !== 1) {
            $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_HOST_INVALID', provider: $provider, panel: $panel->value, key: 'host')->errors[0];
        }
        if ($contourLevel !== null && ! in_array($contourLevel, $effectiveFloor->contourLevels, true)) {
            $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_CONTOUR_INVALID', provider: $provider, panel: $panel->value, key: 'contour')->errors[0];
        }
        $copiedFeatures = [];
        foreach ($features as $feature) {
            if (preg_match('~^[a-z][a-z0-9-]{0,31}$~D', $feature) !== 1 || in_array($feature, $copiedFeatures, true)) {
                $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_FEATURE_INVALID', provider: $provider, panel: $panel->value, key: 'features')->errors[0];
            }
            $copiedFeatures[] = $feature;
        }
        $copiedSettings = [];
        foreach ($settings as $key => $value) {
            if (preg_match('~^[a-z][a-z0-9_.]{0,63}$~D', $key) !== 1) {
                $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_SETTING_INVALID', provider: $provider, panel: $panel->value, key: 'settings')->errors[0];
            }
            $copiedSettings[$key] = $value;
        }
        if ($errors !== []) {
            throw new InvalidSurfaceDefinitionException($errors);
        }
        sort($copiedFeatures);
        ksort($copiedSettings);
        $this->features = $copiedFeatures;
        $this->settings = $copiedSettings;
        $scopes = $levels = [];
        foreach ($effectiveFloor->allowedScopes as $scope) {
            $scopes[] = $scope;
        }
        foreach ($effectiveFloor->contourLevels as $level) {
            $levels[] = $level;
        }
        $this->effectiveFloor = new PrivacyFloor(allowedScopes: $scopes, defaultScope: $effectiveFloor->defaultScope, maxScope: $effectiveFloor->maxScope,
            minorDefaultScope: $effectiveFloor->minorDefaultScope, minorMaxScope: $effectiveFloor->minorMaxScope, contourLevels: $levels);
    }
}
