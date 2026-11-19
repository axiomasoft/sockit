<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Posts\Data\PostView;
use SocKit\Surfaces\Data\SurfaceInstance;

/** Captured owner state accompanies its guarded view until the whole-page output check. */
final readonly class FeedCandidate
{
    /** @param array<string, mixed> $row */
    public function __construct(public PostView $view, public array $row, public SurfaceInstance $surface, public AudienceVerdict $admission) {}
}
