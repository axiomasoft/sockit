<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Panels;

use Illuminate\Support\ServiceProvider;
use SocKit\Panels\PanelDefinitions;

final class PanelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        PanelDefinitions::add($this->app, ProductPanel::class, ArticlePanel::class);
    }
}
