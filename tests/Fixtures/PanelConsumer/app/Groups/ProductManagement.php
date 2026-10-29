<?php

declare(strict_types=1);

namespace Acme\Groups;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class ProductManagement extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.product'))->kind('acme.management')->host('product')->archiveReaders()->features('groups');
    }
}
