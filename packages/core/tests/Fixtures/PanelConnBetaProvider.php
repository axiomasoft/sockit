<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\SockitPanelBuilder;

final class PanelConnBetaProvider extends PanelProvider
{
    public function panel(SockitPanelBuilder $panel): SockitPanelBuilder
    {
        return $panel
            ->id('app.beta')
            ->connection('conn_beta')
            ->hostTypes('board')
            ->modules('reactions', 'posts', 'groups')
            ->floor(new PrivacyFloor(
                allowedScopes: [Scope::Direct, Scope::Platform],
                defaultScope: Scope::Platform,
                maxScope: Scope::Platform,
                minorDefaultScope: Scope::Direct,
                minorMaxScope: Scope::Platform,
                contourLevels: [],
            ));
    }
}
