<?php

declare(strict_types=1);

namespace SocKit\Surfaces;

use Packman\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\FrozenDefinitionException;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Exceptions\SurfaceException;

/**
 * Fluent builder for a panel-bound surface kind definition; freezes upon materialize().
 */
#[Api(since: '1.0')]
final class Surface
{
    public const string SETTING_KEY_PATTERN = '/\A[a-z][a-z0-9_.]{0,63}\z/';

    public const string FEATURE_PATTERN = '/\A[a-z][a-z0-9-]{0,31}\z/';

    private ?PanelId $panel = null;

    private ?SurfaceKindId $kind = null;

    private ?string $hostType = null;

    private string $label = '';

    private ?string $contourLevel = null;

    private ?PrivacyFloor $floor = null;

    /** @var list<string> */
    private array $features = [];

    private bool $archiveReaders = false;

    /** @var array<string, scalar|null> */
    private array $settings = [];

    private bool $frozen = false;

    public function __construct(private readonly string $providerClass = 'inline') {}

    public function panel(PanelId $panel): self
    {
        $this->assertMutable();
        $this->panel = $panel;

        return $this;
    }

    public function kind(string $id): self
    {
        $this->assertMutable();
        $this->kind = new SurfaceKindId($id);

        return $this;
    }

    public function host(string $hostType): self
    {
        $this->assertMutable();

        if (preg_match(HostEntityRef::TYPE_PATTERN, $hostType) !== 1) {
            throw SurfaceException::invalidDefinition('SURFACE_HOST_INVALID', "Invalid surface host type [{$hostType}].", ['host_type' => $hostType]);
        }

        $this->hostType = $hostType;

        return $this;
    }

    public function label(string $label): self
    {
        $this->assertMutable();
        $this->label = $label;

        return $this;
    }

    public function contour(string $level): self
    {
        $this->assertMutable();

        if (preg_match(PrivacyFloor::CONTOUR_LEVEL_PATTERN, $level) !== 1) {
            throw SurfaceException::invalidDefinition('SURFACE_CONTOUR_INVALID', "Invalid contour level [{$level}].", ['contour' => $level]);
        }

        $this->contourLevel = $level;

        return $this;
    }

    public function floor(PrivacyFloor $floor): self
    {
        $this->assertMutable();
        $this->floor = $floor;

        return $this;
    }

    public function features(string ...$features): self
    {
        $this->assertMutable();

        foreach ($features as $feature) {
            if (preg_match(self::FEATURE_PATTERN, $feature) !== 1) {
                throw SurfaceException::invalidDefinition('SURFACE_FEATURE_INVALID', "Invalid surface feature name [{$feature}].", ['feature' => $feature]);
            }
        }

        $this->features = array_values(array_unique($features));

        return $this;
    }

    public function archiveReaders(bool $allow = true): self
    {
        $this->assertMutable();
        $this->archiveReaders = $allow;

        return $this;
    }

    public function setting(string $key, string|int|float|bool|null $value): self
    {
        $this->assertMutable();

        if (preg_match(self::SETTING_KEY_PATTERN, $key) !== 1) {
            throw SurfaceException::invalidDefinition('SURFACE_SETTING_INVALID', "Invalid surface setting key [{$key}].", ['key' => $key]);
        }

        $this->settings[$key] = $value;

        return $this;
    }

    public function materialize(PrivacyFloor $panelFloor): SurfaceDefinition
    {
        $this->assertMutable();

        if (! $this->panel instanceof PanelId) {
            throw SurfaceException::invalidDefinition('SURFACE_PANEL_UNKNOWN', 'Surface requires a PanelId.');
        }

        if (! $this->kind instanceof SurfaceKindId) {
            throw SurfaceException::invalidDefinition('SURFACE_KIND_INVALID', 'Surface requires a SurfaceKindId.');
        }

        if ($this->hostType === null) {
            throw SurfaceException::invalidDefinition('SURFACE_HOST_MISMATCH', 'Surface requires a host entity type alias.');
        }

        $effectiveFloor = $this->floor instanceof PrivacyFloor
            ? $panelFloor->narrowedBy($this->floor)
            : $panelFloor;

        if ($this->contourLevel !== null && ! $effectiveFloor->allowsContourLevel($this->contourLevel)) {
            throw SurfaceException::invalidDefinition(
                'SURFACE_CONTOUR_LEVEL_UNKNOWN',
                "Surface contour level [{$this->contourLevel}] is not permitted by panel floor.",
                ['panel' => $this->panel->value, 'kind' => $this->kind->value, 'contour_level' => $this->contourLevel],
            );
        }

        $this->frozen = true;

        return new SurfaceDefinition(
            panel: $this->panel,
            kind: $this->kind,
            hostType: $this->hostType,
            label: $this->label !== '' ? $this->label : $this->kind->value,
            contourLevel: $this->contourLevel,
            effectiveFloor: $effectiveFloor,
            features: $this->features,
            archiveReaders: $this->archiveReaders,
            settings: $this->settings,
            provider: $this->providerClass,
        );
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw FrozenDefinitionException::forBuilder(self::class);
        }
    }
}
