<?php

declare(strict_types=1);

namespace SocKit\Panels;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use SocKit\Panels\Data\PanelDefinition;

/**
 * Host SPI declaring a SocKit storage-boundary panel and optional cross-panel validation hook.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class PanelProvider
{
    abstract public function panel(SockitPanelBuilder $panel): SockitPanelBuilder;

    /** @param array<string, PanelDefinition> $panels */
    public function validate(array $panels): void {}
}
