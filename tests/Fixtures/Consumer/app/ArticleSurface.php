<?php

declare(strict_types=1);

namespace Acme;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class ArticleSurface extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.a'))->kind('acme.runtime')->host('article');
    }
}
