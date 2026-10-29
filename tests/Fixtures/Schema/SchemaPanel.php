<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Illuminate\Contracts\Config\Repository;
use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class SchemaPanel extends PanelProvider
{
    public ?Panel $builder = null;

    public function __construct(private readonly Repository $config) {}

    public function panel(Panel $panel): Panel
    {
        $this->builder = $panel;

        return $panel->id('acme.articles')->hostTypes('article')->prefix('articles_')
            ->modules(...array_map(Scenario::text(...), Scenario::entries($this->config->get('schema_fixture.selected', ['acme.demo-links', 'acme.demo-targets']))))
            ->tables(array_map(Scenario::text(...), Scenario::object($this->config->get('schema_fixture.tables', []))));
    }
}
