<?php

declare(strict_types=1);

namespace Acme;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\PanelStorage;

final class ArticlePanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $tables = array_combine(PanelStorage::KERNEL_ENTITIES, PanelStorage::KERNEL_ENTITIES);

        return $panel->id('acme.a')->hostTypes('article')->connection('owner_a')->schema('panel_a')->prefix('a_')->tables($tables)->modules('acme.runtime');
    }
}
