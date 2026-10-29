<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

class ReactionSurface extends SurfaceProvider
{
    public function __construct(private readonly string $id = 'a', private readonly bool $enabled = true) {}

    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.'.$this->id))->kind('acme.runtime')->host('article')->archiveReaders()->features(...($this->enabled ? ['reactions'] : []));
    }
}
