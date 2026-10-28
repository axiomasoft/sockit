<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;
use SocKit\Panels\PanelStorage;

class RuntimePanel extends PanelProvider
{
    public function __construct(private readonly string $id = 'acme.a', private readonly string $connection = 'owner_a', private readonly string $schema = 'panel_a', private readonly string $prefix = 'a_') {}

    public function panel(Panel $panel): Panel
    {
        $tables = [];
        foreach (PanelStorage::KERNEL_ENTITIES as $entity) {
            $tables[$entity] = $entity;
        }

        return $panel->id($this->id)->hostTypes('article')->connection($this->connection)->schema($this->schema)->prefix($this->prefix)->tables($tables);
    }
}
