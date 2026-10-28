<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Panels;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class ProductPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // The same host alias may be bound independently in two panels.
        return $panel->id('host.products')->hostTypes('article', 'product')->connection('social')->prefix('products_')->modules('other.module');
    }
}
