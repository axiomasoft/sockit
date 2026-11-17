<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\SockitPanelBuilder;

final class PanelCustomMapProvider extends PanelProvider
{
    public function panel(SockitPanelBuilder $panel): SockitPanelBuilder
    {
        return $panel
            ->id('sockit.custom-map')
            ->hostTypes('board')
            ->prefix('cm_')
            ->tables([
                'posts' => 'wall_posts',
                'groups' => 'club_groups',
            ])
            ->modules('reactions', 'posts', 'groups')
            ->floor(new PrivacyFloor(
                allowedScopes: [Scope::Direct, Scope::Group, Scope::Contour, Scope::Platform],
                defaultScope: Scope::Platform,
                maxScope: Scope::Platform,
                minorDefaultScope: Scope::Direct,
                minorMaxScope: Scope::Platform,
                contourLevels: ['team'],
            ));
    }
}
