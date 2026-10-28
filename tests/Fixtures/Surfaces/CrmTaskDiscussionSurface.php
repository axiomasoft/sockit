<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Panels\Data\PanelId;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;

/** Legacy example name; Article reactions only. Discussions remain external. */
final class CrmTaskDiscussionSurface extends SurfaceProvider
{
    public function surface(Surface $surface): Surface
    {
        return $surface->panel(new PanelId('crm.public'))->kind('crm.article')->host('article')->features('reactions')->setting(key: 'display_limit', value: 10);
    }

    public function validate(array $surfaces): void
    {
        foreach ($surfaces as $surface) {
            if ($surface->provider === self::class && $surface->settings !== ['display_limit' => 10]) {
                throw new \RuntimeException('Unknown surface settings');
            }
        }
    }
}
