<?php

declare(strict_types=1);

namespace SocKit\Modules;

use SocKit\Attributes\Spi;
use SocKit\Attributes\SpiKind;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;
use SocKit\Modules\Contracts\ModuleSettingsSchema;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Surfaces\Contracts\TargetResolver;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ModuleRegistrar
{
    /** @var list<string> */
    private array $features = [];

    /** @var list<string> */
    private array $surfaces = [];

    /** @var class-string<ModuleSettingsSchema>|null */
    private ?string $schema = null;

    /** @var array<string, class-string<TargetResolver>> */
    private array $targets = [];

    private bool $frozen = false;

    public function freeze(): void
    {
        $this->frozen = true;
    }

    private function mutable(): void
    {
        if ($this->frozen) {
            $this->fail(key: 'registration', rule: 'MODULE_REGISTRATION_CLOSED');
        }
    }

    /** Register an immutable FQCN capability. Instances are resolved only at operation time. */
    public function target(string $alias, string $resolver): self
    {
        $this->mutable();
        if (preg_match('/^[a-z][a-z0-9_.-]{0,95}$/D', $alias) !== 1 || ! is_subclass_of($resolver, TargetResolver::class) || ! new \ReflectionClass($resolver)->isInstantiable()) {
            $this->fail(key: 'targets.'.$alias, rule: 'MODULE_TARGET_INVALID');
        }
        if (isset($this->targets[$alias])) {
            throw new InvalidConfigurationException(message: 'Duplicate target capability', detail: ['module' => $this->module, 'key' => 'targets.'.$alias, 'rule' => 'MODULE_TARGET_DUPLICATE']);
        }
        $this->targets[$alias] = $resolver;

        return $this;
    }

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

    /** @return array{surfaces:list<string>, features:list<string>, settings:class-string<ModuleSettingsSchema>|null, targets:array<string, class-string<TargetResolver>>} */
    public function declarations(): array
    {
        return ['surfaces' => $this->surfaces, 'features' => $this->features, 'settings' => $this->schema, 'targets' => $this->targets];
    }

    private function fail(string $key, string $rule): never
    {
        throw new InvalidModuleException([['module' => $this->module, 'key' => $key, 'rule' => $rule]]);
    }
}
