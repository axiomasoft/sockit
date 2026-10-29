<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class MigrationPanel extends PanelProvider
{
    public function __construct(private readonly bool $selected, private readonly string $prefix = 'article_', private readonly bool $consumers = false) {}

    public function panel(Panel $panel): Panel
    {
        return $panel->id('acme.article')->hostTypes('article')->connection('owner_a')->schema('panel_a')->prefix($this->prefix)
            ->modules(...[...($this->selected ? ['acme.demo-targets'] : []), ...($this->consumers ? ['acme.runtime'] : [])]);
    }
}
