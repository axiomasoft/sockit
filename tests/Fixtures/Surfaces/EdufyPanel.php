<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario as Wire;

final class EdufyPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('edufy.community')->hostTypes('edufy.class')->prefix('edufy_')->modules('social.posts', 'social.reactions')
            ->floor(Wire::floor(['allowed_scopes' => ['direct', 'group', 'contour'], 'default_scope' => 'contour', 'max_scope' => 'contour',
                'minor_default_scope' => 'direct', 'minor_max_scope' => 'contour', 'contour_levels' => ['class']]));
    }
}
