<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;

class ReactionPanel extends PanelProvider
{
    public function __construct(private readonly string $id = 'a', private readonly bool $enabled = true) {}

    public function panel(Panel $panel): Panel
    {
        $panel->id('acme.'.$this->id)->hostTypes('article')->connection('owner_'.$this->id)->schema('panel_'.$this->id)->prefix($this->id.'_')
            ->modules(...($this->enabled ? ['sockit.reactions'] : []));
        if ($this->enabled) {
            $panel->moduleSettings('sockit.reactions', ['kinds' => ['like', 'thanks']]);
        }

        return $panel;
    }
}
