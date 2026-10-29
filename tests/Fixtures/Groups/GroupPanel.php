<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class GroupPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('acme.a')->hostTypes('article')->connection('owner_a')->schema('panel_a')->prefix('a_')->modules('sockit.groups');
    }
}
