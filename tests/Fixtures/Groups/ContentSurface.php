<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

final class ContentSurface extends SurfaceProvider
{
    public function __construct(private readonly string $kind = 'acme.club') {}

    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('acme.a'))->kind($this->kind)->host('sockit.group')->features('posts', 'reactions')->archiveReaders()
            ->floor(new PrivacyFloor(allowedScopes: [Scope::Direct, Scope::Group], defaultScope: Scope::Direct, maxScope: Scope::Group,
                minorMaxScope: Scope::Direct, minorDefaultScope: Scope::Direct, contourLevels: []));
    }
}
