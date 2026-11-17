<?php

declare(strict_types=1);

namespace SocKit\Audience\Internal;

use Packman\Attributes\Internal;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\GroupRef;
use SocKit\Panels\Data\PanelId;

/**
 * Captures local group, viewer membership and bound management surface rows without external calls or locks.
 */
#[Internal]
interface SnapshotsLocalGroupState
{
    /** @param list<GroupRef> $groups */
    public function snapshotLocalGroupState(ActorContext $actor, PanelId $panel, array $groups): string;
}
