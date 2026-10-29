<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\PanelConsumer;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

/** Same server/schema/tables behind a different connection name. Boot alone cannot prove the alias. */
final class DoctorAliasPanel extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('acme.alias')->hostTypes('other')->connection('owner_alias')->schema('panel_a')->prefix('article_');
    }
}
