<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Surfaces;

use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Exceptions\InvalidSurfaceDefinitionException;
use SocKit\Surfaces\Surface;
use SocKit\Surfaces\SurfaceProvider;
use SocKit\Tests\Unit\Audience\Support\Scenario as Wire;

class ConfiguredSurface extends SurfaceProvider
{
    public ?Surface $builder = null;

    /** @param array<string, mixed> $recipe */
    public function __construct(private readonly array $recipe = []) {}

    public function surface(Surface $surface): Surface
    {
        $this->builder = $surface;
        if (($this->recipe['throw'] ?? false) === true) {
            throw new \RuntimeException('secret provider value');
        }
        if (($this->recipe['missing_panel'] ?? false) !== true) {
            $surface->panel(new PanelId(Wire::text($this->recipe['panel'] ?? 'crm.public')));
        }
        $surface->kind(Wire::text($this->recipe['kind'] ?? 'crm.article'))->host(Wire::text($this->recipe['host'] ?? 'article'));
        $features = array_map(Wire::text(...), Wire::entries($this->recipe['features'] ?? ['reactions']));
        $surface->features(...$features);
        if (isset($this->recipe['floor'])) {
            $surface->floor(Wire::floor(Wire::object($this->recipe['floor'])));
        }
        if (isset($this->recipe['contour'])) {
            $surface->contour(Wire::text($this->recipe['contour']));
        }
        if (isset($this->recipe['archive_readers'])) {
            $surface->archiveReaders(Wire::flag($this->recipe['archive_readers']));
        }
        foreach (Wire::object($this->recipe['settings'] ?? []) as $key => $value) {
            if (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null) {
                throw new \RuntimeException('Invalid fixture setting');
            }
            $surface->setting(key: $key, value: $value);
        }

        return $surface;
    }

    public function validate(array $surfaces): void
    {
        if (($this->recipe['early_resolve'] ?? false) === true) {
            foreach ([Modules::class, PanelDefinitions::class, SurfaceDefinitions::class] as $contract) {
                try {
                    app($contract);
                } catch (SocKitException) {
                    continue;
                }
                throw new \RuntimeException('Partial registry was published');
            }
        }
        if (($this->recipe['reject_graph'] ?? false) === true) {
            throw InvalidSurfaceDefinitionException::at(code: 'SURFACE_POLICY_INVALID', provider: static::class, key: 'validate');
        }
        foreach ($surfaces as $surface) {
            if ($surface->provider !== static::class) {
                continue;
            }
            foreach ($surface->settings as $key => $value) {
                if ($key !== 'display_limit' || ! is_int($value) || $value < 1 || $value > 100) {
                    throw InvalidSurfaceDefinitionException::at(code: 'SURFACE_SETTING_INVALID', provider: static::class, panel: $surface->panel->value, key: 'settings.'.$key);
                }
            }
        }
    }
}
