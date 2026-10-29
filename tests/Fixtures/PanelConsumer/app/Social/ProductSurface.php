<?php

declare(strict_types=1);

namespace Acme\Social;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class ProductSurface extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.product'))->kind('acme.social')->host('product')->archiveReaders()->features('reactions');
    }
}
