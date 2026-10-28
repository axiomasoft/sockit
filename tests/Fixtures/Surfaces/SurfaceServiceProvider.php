<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use Illuminate\Support\ServiceProvider;
use SocKit\Surfaces\Surfaces;

final class SurfaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Surfaces::add($this->app, EdufyClassFeedSurface::class, CrmTaskDiscussionSurface::class, CrmTeamArticleSurface::class);
    }
}
