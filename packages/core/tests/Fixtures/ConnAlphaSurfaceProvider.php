<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class ConnAlphaSurfaceProvider extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface
            ->panel(new PanelId('app.alpha'))
            ->kind('shared.wall')
            ->host('board')
            ->features('posts', 'reactions', 'groups')
            ->archiveReaders(true);
    }
}
