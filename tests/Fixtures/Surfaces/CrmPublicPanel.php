<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class CrmPublicPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('crm.public')->hostTypes('article', 'task')->prefix('crm_public_')->modules('social.reactions');
    }
}
