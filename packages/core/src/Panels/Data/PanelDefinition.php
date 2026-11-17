<?php

declare(strict_types=1);

namespace SocKit\Panels\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;

/**
 * Immutable compiled definition of a SocKit storage-boundary panel.
 */
#[Api(since: '1.0')]
final readonly class PanelDefinition
{
    /**
     * @param  list<string>  $hostTypes
     * @param  list<string>  $modules
     * @param  array<string, array<string, scalar|array<array-key, mixed>|null>>  $moduleSettings
     */
    public function __construct(
        public PanelId $id,
        public array $hostTypes,
        public PanelStorage $storage,
        public array $modules,
        public array $moduleSettings,
        public PrivacyFloor $floor,
        public string $provider,
    ) {}

    public function acceptsHostType(string $hostType): bool
    {
        return in_array($hostType, $this->hostTypes, true);
    }

    public function hasModule(string $moduleId): bool
    {
        return in_array($moduleId, $this->modules, true);
    }

    /** @return array<string, scalar|array<array-key, mixed>|null> */
    public function settingsFor(string $moduleId): array
    {
        return $this->moduleSettings[$moduleId] ?? [];
    }
}
