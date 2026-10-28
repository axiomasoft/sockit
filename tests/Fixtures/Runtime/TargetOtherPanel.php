<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class TargetOtherPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('acme.b')->hostTypes('article')->connection('owner_b')->prefix('b_')->modules('acme.other');
    }
}
