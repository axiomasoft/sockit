<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use Illuminate\Contracts\Config\Repository;
use SocKit\Configuration\Data\ModulesConfig;
use SocKit\Panels\Panel;
use SocKit\Panels\PanelProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class SocialPanel extends PanelProvider
{
    public function __construct(private readonly Repository $config) {}

    public function panel(Panel $panel): Panel
    {
        $panel->id('host.social')->hostTypes('article')->prefix('social_')->connection('owner');
        $panel->modules(...array_map(Scenario::text(...), Scenario::entries($this->config->get('social_fixture.selected', []))));
        foreach (Scenario::object($this->config->get('social_fixture.settings', [])) as $module => $settings) {
            $panel->moduleSettings($module, ModulesConfig::settingsMap($settings, $module));
        }

        return $panel;
    }
}
