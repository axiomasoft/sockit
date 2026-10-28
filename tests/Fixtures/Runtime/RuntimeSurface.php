<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

class RuntimeSurface extends SurfaceProvider
{
    public function __construct(private readonly string $panel = 'acme.a') {}

    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId($this->panel))->kind('acme.runtime')->host('article');
    }
}
