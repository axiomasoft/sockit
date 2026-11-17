<?php

declare(strict_types=1);

namespace SocKit\Modules\Reactions\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Modules\Reactions\Data\ReactionCountsView;
use SocKit\Modules\Reactions\Data\RemoveReactionCommand;
use SocKit\Operations\Data\OperationResult;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Capability contract for panel-isolated Reactions operations and projection rebuilds.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ReactionService
{
    public function add(AddReactionCommand $command): OperationResult;

    public function remove(RemoveReactionCommand $command): OperationResult;

    public function counts(SurfaceRef $surface, HostEntityRef $target): ReactionCountsView;

    /**
     * Recomputes reaction_counts deterministically for a panel without firing side-effect notifications.
     */
    public function rebuildCounts(PanelId $panel): int;
}
