<?php

declare(strict_types=1);

namespace Acme\Social;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class ArticleSurface extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.article'))->kind('acme.social')->host('article')->archiveReaders()->features('reactions');
    }
}
