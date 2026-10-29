<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use Illuminate\Support\ServiceProvider;
use SocKit\Panels\PanelDefinitions;

final class PanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        PanelDefinitions::add($this->app, EdufyPanel::class, CrmPublicPanel::class, CrmTeamPanel::class);
    }
}
