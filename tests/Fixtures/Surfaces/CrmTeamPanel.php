<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class CrmTeamPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('crm.team')->hostTypes('article', 'task')->prefix('crm_team_')->modules('social.reactions');
    }
}
