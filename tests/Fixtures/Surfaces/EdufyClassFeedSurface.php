<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class EdufyClassFeedSurface extends SurfaceProvider
{
    public ?Surface $builder = null;

    public function surface(Surface $surface): Surface
    {
        $this->builder = $surface;

        return $surface->panel(new PanelId('edufy.community'))->kind('edufy.class-feed')->host('edufy.class')
            ->label('Class feed')->contour('class')->features('posts', 'reactions');
    }
}
