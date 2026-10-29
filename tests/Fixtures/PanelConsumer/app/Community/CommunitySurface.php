<?php

declare(strict_types=1);

namespace Acme\Community;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class CommunitySurface extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.community'))->kind('acme.class-community')->host('class')->archiveReaders()->features('posts', 'reactions');
    }
}
