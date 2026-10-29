<?php

declare(strict_types=1);

namespace Acme\Groups;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class ProductContent extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.product'))->kind('acme.content')->host('sockit.group')->archiveReaders()->features('posts', 'reactions');
    }
}
