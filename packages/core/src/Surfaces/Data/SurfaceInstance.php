<?php

declare(strict_types=1);

namespace SocKit\Surfaces\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Enums\SurfaceLifecycleState;

/**
 * Immutable snapshot view of a persisted runtime surface instance.
 */
#[Api(since: '1.0')]
final readonly class SurfaceInstance
{
    public function __construct(
        public string $id,
        public PanelId $panel,
        public SurfaceKindId $kind,
        public HostEntityRef $host,
        public Incarnation $incarnation,
        public SurfaceLifecycleState $lifecycleState,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $archivedAt = null,
    ) {}

    public function ref(): SurfaceRef
    {
        return new SurfaceRef($this->panel, $this->kind, $this->host, $this->incarnation);
    }

    public function isArchived(): bool
    {
        return $this->lifecycleState === SurfaceLifecycleState::Archived;
    }
}
