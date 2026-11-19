<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Support\Data\ResourceRef;
use SocKit\Surfaces\Data\SurfaceInstance;
use SocKit\Surfaces\Data\TargetResolution;

/** Operation-local sampled facts, never permission to bypass a current guard. */
final readonly class GroupAccess
{
    /** @param array<string, mixed> $group
     * @param array<string, mixed> $member */
    public function __construct(public OperationHandle $management, public ResourceRef $resource, public array $group,
        public array $member, public TargetResolution $host, public SurfaceInstance $instance, public AudienceVerdict $verdict) {}
}
