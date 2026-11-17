<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\SockitPanelBuilder;

final class PanelPgSchemaAlphaProvider extends PanelProvider
{
    public function panel(SockitPanelBuilder $panel): SockitPanelBuilder
    {
        return $panel
            ->id('sockit.pg-alpha')
            ->connection('pgsql')
            ->schema('tenant_alpha')
            ->prefix('pa_')
            ->tables(['posts' => 'wall_posts'])
            ->hostTypes('board', 'user', 'group')
            ->modules('reactions', 'posts', 'groups')
            ->floor(new PrivacyFloor(
                allowedScopes: [Scope::Direct, Scope::Group, Scope::Contour, Scope::Platform],
                defaultScope: Scope::Platform,
                maxScope: Scope::Platform,
                minorDefaultScope: Scope::Direct,
                minorMaxScope: Scope::Group,
                contourLevels: ['team'],
            ));
    }
}
