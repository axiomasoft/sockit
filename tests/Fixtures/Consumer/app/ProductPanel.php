<?php

declare(strict_types=1);

namespace Acme;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\PanelStorage;

final class ProductPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('acme.b')->hostTypes('article')->connection('owner_b')->schema('panel_b')->prefix('b_')->tables(array_combine(PanelStorage::KERNEL_ENTITIES, PanelStorage::KERNEL_ENTITIES))->modules('acme.runtime');
    }
}
