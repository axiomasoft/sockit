<?php

declare(strict_types=1);

namespace SocKit\Surfaces;

use SocKit\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Exceptions\FloorWidensException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Exceptions\FrozenDefinitionException;
use SocKit\Surfaces\Exceptions\InvalidSurfaceDefinitionException;

#[Api(since: '1.0')]
final class Surface
{
    private ?PanelId $panelId = null;

    private string $identifier = '';

    private string $hostType = '';

    private string $displayLabel = '';

    private ?string $contourLevel = null;

    private ?PrivacyFloor $privacyFloor = null;

    /** @var list<string> */
    private array $selectedFeatures = [];

    private bool $allowArchiveReaders = false;

    /** @var array<string, string|int|bool|null> */
    private array $settings = [];

    private bool $frozen = false;

    public function __construct(private readonly string $provider = '') {}

    public function panel(PanelId $panel): self
    {
        $this->mutable();
        $this->panelId = $panel;

        return $this;
    }

    public function kind(string $id): self
    {
        $this->mutable();
        $this->identifier = $id;

        return $this;
    }

    public function host(string $hostType): self
    {
        $this->mutable();
        $this->hostType = $hostType;

        return $this;
    }

    public function label(string $label): self
    {
        $this->mutable();
        $this->displayLabel = $label;

        return $this;
    }

    public function contour(string $level): self
    {
        $this->mutable();
        $this->contourLevel = $level;

        return $this;
    }

    public function floor(PrivacyFloor $floor): self
    {
        $this->mutable();
        $this->privacyFloor = $floor;

        return $this;
    }

    public function features(string ...$features): self
    {
        $this->mutable();
        $this->selectedFeatures = array_values($features);

        return $this;
    }

    public function archiveReaders(bool $allow = true): self
    {
        $this->mutable();
        $this->allowArchiveReaders = $allow;

        return $this;
    }

    public function setting(string $key, string|int|bool|null $value): self
    {
        $this->mutable();
        $this->settings[$key] = $value;

        return $this;
    }

    public function panelId(): ?PanelId
    {
        return $this->panelId;
    }

    /** Close a draft even when its provider fails or returns a replacement. */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function materialize(PrivacyFloor $platform): SurfaceDefinition
    {
        $this->mutable();
        $this->freeze();
        $panel = $this->panelId->value ?? '';
        $errors = [];
        if ($this->panelId === null) {
            $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_PANEL_UNKNOWN', provider: $this->provider, key: 'panel')->errors[0];
        }
        try {
            $kind = new SurfaceKindId($this->identifier);
        } catch (InvalidPayloadException) {
            $kind = null;
            $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_KIND_INVALID', provider: $this->provider, panel: $panel, key: 'kind')->errors[0];
        }
        try {
            $floor = $platform->narrowedBy($this->privacyFloor ?? $platform);
        } catch (FloorWidensException) {
            $floor = $platform;
            $errors[] = InvalidSurfaceDefinitionException::at(code: 'SURFACE_FLOOR_WIDENS', provider: $this->provider, panel: $panel, key: 'floor')->errors[0];
        }
        if ($errors !== []) {
            throw new InvalidSurfaceDefinitionException($errors);
        }
        assert($this->panelId !== null && $kind !== null);

        return new SurfaceDefinition(panel: $this->panelId, kind: $kind, hostType: $this->hostType, label: $this->displayLabel,
            contourLevel: $this->contourLevel, effectiveFloor: $floor, features: $this->selectedFeatures,
            archiveReaders: $this->allowArchiveReaders, settings: $this->settings, provider: $this->provider);
    }

    private function mutable(): void
    {
        if ($this->frozen) {
            throw new FrozenDefinitionException(message: 'Surface builder is frozen', detail: ['provider' => $this->provider, 'rule' => 'SURFACE_REGISTRY_PHASE']);
        }
    }
}
