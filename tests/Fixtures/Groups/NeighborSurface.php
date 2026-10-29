<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class NeighborSurface extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.b'))->kind('acme.runtime')->host('article')->features('groups');
    }
}
