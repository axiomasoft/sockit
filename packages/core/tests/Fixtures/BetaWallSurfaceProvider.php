<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class BetaWallSurfaceProvider extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface
            ->panel(new PanelId('sockit.beta'))
            ->kind('sockit.wall')
            ->host('board')
            ->label('Beta Wall')
            ->features('posts', 'reactions', 'groups');
    }
}
