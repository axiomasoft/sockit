<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Panels;

use SocKit\Configuration\Data\ModulesConfig;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\Panel;
use SocKit\Panels\PanelDefinition;
use SocKit\Panels\PanelProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario;

class ConfiguredPanel extends PanelProvider
{
    public ?Panel $builder = null;

    /** @param array<string, mixed> $recipe */
    public function __construct(private readonly array $recipe) {}

    public function panel(Panel $panel): Panel
    {
        $panel->id(Scenario::text($this->recipe['id']))->hostTypes(...array_map(Scenario::text(...), Scenario::entries($this->recipe['hosts'] ?? ['article'])))
            ->connection(Scenario::text($this->recipe['connection'] ?? 'social'))->prefix(Scenario::text($this->recipe['prefix'] ?? 'first_'))
            ->modules(...array_map(Scenario::text(...), Scenario::entries($this->recipe['modules'] ?? [])));
        foreach (Scenario::object($this->recipe['settings'] ?? []) as $module => $settings) {
            $values = ModulesConfig::settingsMap($settings, $module);
            $panel->moduleSettings($module, $values);
        }
        if (isset($this->recipe['tables'])) {
            $tables = array_map(Scenario::text(...), Scenario::object($this->recipe['tables']));
            $panel->tables($tables);
        }
        if (isset($this->recipe['floor'])) {
            $panel->floor(Scenario::floor(Scenario::object($this->recipe['floor'])));
        }
        $this->builder = $panel;

        return $panel;
    }

    /** @param array<string, PanelDefinition> $panels */
    public function validate(array $panels): void
    {
        if (($this->recipe['reject_graph'] ?? false) === true) {
            throw new InvalidPanelException(message: 'Fixture validation rejects the whole graph', detail: ['panel' => Scenario::text($this->recipe['id']), 'provider' => self::class, 'key' => 'panels', 'rule' => 'PANEL_HOST_INVALID']);
        }
    }
}
