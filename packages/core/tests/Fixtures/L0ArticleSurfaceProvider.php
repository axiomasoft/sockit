<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class L0ArticleSurfaceProvider extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface
            ->panel(new PanelId('sockit.l0'))
            ->kind('host.article')
            ->host('article')
            ->label('Article Reactions')
            ->features('reactions');
    }
}
