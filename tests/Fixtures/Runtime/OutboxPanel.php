<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Panels\Panel;

final class OutboxPanel extends RuntimePanel
{
    /** @param list<string> $selected */
    public function __construct(string $id = 'acme.a', string $connection = 'owner_a', string $schema = 'panel_a', string $prefix = 'a_', private readonly array $selected = ['acme.runtime'])
    {
        parent::__construct(id: $id, connection: $connection, schema: $schema, prefix: $prefix);
    }

    public function panel(Panel $panel): Panel
    {
        return parent::panel($panel)->modules(...$this->selected);
    }
}
