<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Panels\Data\PanelId;

/**
 * Immutable compiled definition of a panel-bound surface kind.
 */
#[Api(since: '1.0')]
final readonly class SurfaceDefinition
{
    /**
     * @param  list<string>  $features
     * @param  array<string, scalar|null>  $settings
     */
    public function __construct(
        public PanelId $panel,
        public SurfaceKindId $kind,
        public string $hostType,
        public string $label,
        public ?string $contourLevel,
        public PrivacyFloor $effectiveFloor,
        public array $features,
        public bool $archiveReaders,
        public array $settings,
        public string $provider,
    ) {}

    public function key(): string
    {
        return $this->panel->value.'/'.$this->kind->value;
    }

    public function supportsFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
