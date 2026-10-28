<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use SocKit\Actors\Data\ActorContext;
use SocKit\Attributes\Api;
use SocKit\Panels\PanelDefinition;
use SocKit\Surfaces\Data\SurfaceDefinition;
use SocKit\Surfaces\Data\SurfaceRef;

/** A context snapshot, never bearer authorization. Revalidate through OperationHandles at every runtime entry. */
#[Api(since: '1.0')]
final readonly class OperationHandle
{
    public function __construct(public SurfaceRef $surface, public ActorContext $actor, public PanelDefinition $panel, public SurfaceDefinition $definition) {}
}
