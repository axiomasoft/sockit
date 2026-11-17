<?php

declare(strict_types=1);

namespace SocKit\Operations\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Panels\Data\PanelId;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface OutboxRelay
{
    public function relayPending(PanelId $panel, int $batchSize = 100, ReplayMode $mode = ReplayMode::Normal): int;
}
