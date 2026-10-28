<?php

declare(strict_types=1);

namespace SocKit\Modules;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Modules\Exceptions\InvalidModuleException;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ModuleRegistrar
{
    /** @var list<string> */
    private array $features = [];

    /** @var list<string> */
    private array $surfaces = [];

    /** @var class-string<ModuleSettingsSchema>|null */
    private ?string $schema = null;

    public function __construct(public readonly string $module) {}

    public function settings(string $schema): self
    {
        if ($this->schema !== null) {
            $this->fail(key: 'settings', rule: 'MODULE_SETTINGS_SCHEMA_DUPLICATE');
        }
        if (! is_subclass_of($schema, ModuleSettingsSchema::class) || ! new \ReflectionClass($schema)->isInstantiable()) {
            $this->fail(key: 'settings', rule: 'MODULE_SETTINGS_SCHEMA_INVALID');
        }
        $this->schema = $schema;

        return $this;
    }

    public function feature(string $feature): self
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $feature) !== 1) {
            $this->fail(key: 'features', rule: 'MODULE_FEATURE_INVALID');
        }
        $this->features[] = $feature;

        return $this;
    }

    public function surface(string $provider): self
    {
        $this->surfaces[] = $provider;

        return $this;
    }

    /** @return array{surfaces:list<string>, features:list<string>, settings:class-string<ModuleSettingsSchema>|null} */
    public function declarations(): array
    {
        return ['surfaces' => $this->surfaces, 'features' => $this->features, 'settings' => $this->schema];
    }

    private function fail(string $key, string $rule): never
    {
        throw new InvalidModuleException([['module' => $this->module, 'key' => $key, 'rule' => $rule]]);
    }
}
