<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class ProductMigrationPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('acme.product')->hostTypes('product')->connection('owner_a')->schema('panel_b')->prefix('product_');
    }
}
