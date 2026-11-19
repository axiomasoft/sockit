<?php

declare(strict_types=1);

namespace SocKit\Panels;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class PanelProvider
{
    abstract public function panel(Panel $panel): Panel;

    /** Validate the entire private draft, before any registry becomes available.
     * @param array<string, PanelDefinition> $panels */
    public function validate(array $panels): void {}
}
