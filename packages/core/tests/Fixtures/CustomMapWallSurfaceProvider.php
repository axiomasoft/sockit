<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class CustomMapWallSurfaceProvider extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface
            ->panel(new PanelId('sockit.custom-map'))
            ->kind('sockit.wall')
            ->host('board')
            ->label('Custom Map Wall')
            ->features('posts', 'reactions', 'groups');
    }
}
