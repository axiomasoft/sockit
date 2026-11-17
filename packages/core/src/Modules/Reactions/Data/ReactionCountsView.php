<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Data;

use Packman\Attributes\Api;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Immutable aggregated reaction counts snapshot for a panel surface target.
 */
#[Api(since: '1.0')]
final readonly class ReactionCountsView
{
    /** @param array<string, int> $counts */
    public function __construct(
        public SurfaceRef $surface,
        public HostEntityRef $target,
        public array $counts,
        public int $total,
        public Revision $sourceRevision,
    ) {}
}
