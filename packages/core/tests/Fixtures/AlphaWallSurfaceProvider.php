<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class AlphaWallSurfaceProvider extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface
            ->panel(new PanelId('sockit.alpha'))
            ->kind('sockit.wall')
            ->host('board')
            ->label('Alpha Wall')
            ->features('posts', 'reactions', 'groups');
    }
}
