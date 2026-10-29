<?php

declare(strict_types=1);

namespace SocKit\Panels;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;

#[Spi(kind: SpiKind::Extend, since: '1.0')]
abstract class PanelProvider
{
    abstract public function panel(Panel $panel): Panel;

    /** Validate the entire private draft, before any registry becomes available.
     * @param array<string, PanelDefinition> $panels */
    public function validate(array $panels): void {}
}
