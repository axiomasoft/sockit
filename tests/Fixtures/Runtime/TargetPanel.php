<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

final class TargetPanel extends PanelProvider
{
    /** @param list<string> $modules */
    public function __construct(private readonly array $modules = ['acme.runtime']) {}

    public function panel(Panel $panel): Panel
    {
        return $panel->id('acme.a')->hostTypes('article', 'product')->connection('owner_a')->prefix('a_')->modules(...$this->modules);
    }
}
