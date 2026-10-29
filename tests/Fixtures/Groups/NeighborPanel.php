<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class NeighborPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('acme.b')->hostTypes('article')->connection('owner_b')->schema('panel_b')->prefix('b_')->modules('sockit.groups');
    }
}
