<?php

declare(strict_types=1);

namespace SocKit\Panels;

use Packman\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Panels\Data\PanelDefinition;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Data\PanelStorage;
use SocKit\Panels\Exceptions\FrozenDefinitionException;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Support\Data\HostEntityRef;

/**
 * Fluent builder for compiling an immutable {@see PanelDefinition}; freezes upon materialize().
 */
#[Api(since: '1.0')]
final class SockitPanelBuilder
{
    private ?PanelId $id = null;

    /** @var list<string> */
    private array $hostTypes = [];

    private string $connection = '';

    private ?string $schema = null;

    private string $prefix = 'sk_';

    /** @var array<string, string> */
    private array $tables = [];

    /** @var array<string, class-string> */
    private array $models = [];

    /** @var list<string> */
    private array $modules = [];

    /** @var array<string, array<string, scalar|array<array-key, mixed>|null>> */
    private array $moduleSettings = [];

    private ?PrivacyFloor $floor = null;

    private bool $frozen = false;

    public function __construct(private readonly string $providerClass = 'inline') {}

    public function id(string $id): self
    {
        $this->assertMutable();
        $this->id = new PanelId($id);

        return $this;
    }

    public function hostTypes(string ...$aliases): self
    {
        $this->assertMutable();
        $normalized = [];

        foreach ($aliases as $alias) {
            if (preg_match(HostEntityRef::TYPE_PATTERN, $alias) !== 1) {
                throw PanelException::invalidConfig('PANEL_HOST_INVALID', "Invalid panel host alias [{$alias}].", ['host_type' => $alias]);
            }

            $normalized[] = $alias;
        }

        $this->hostTypes = array_values(array_unique($normalized));

        return $this;
    }

    public function connection(string $name): self
    {
        $this->assertMutable();
        $this->connection = $name;

        return $this;
    }

    public function schema(?string $schema): self
    {
        $this->assertMutable();
        $this->schema = $schema;

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->assertMutable();
        $this->prefix = $prefix;

        return $this;
    }

    /** @param array<string, string> $tables */
    public function tables(array $tables): self
    {
        $this->assertMutable();
        $this->tables = $tables;

        return $this;
    }

    /** @param array<string, class-string> $models */
    public function models(array $models): self
    {
        $this->assertMutable();
        $this->models = $models;

        return $this;
    }

    public function modules(string ...$ids): self
    {
        $this->assertMutable();
        $this->modules = array_values(array_unique($ids));

        return $this;
    }

    /** @param array<string, scalar|array<array-key, mixed>|null> $settings */
    public function moduleSettings(string $module, array $settings): self
    {
        $this->assertMutable();
        $this->moduleSettings[$module] = $settings;

        return $this;
    }

    public function floor(PrivacyFloor $floor): self
    {
        $this->assertMutable();
        $this->floor = $floor;

        return $this;
    }

    public function materialize(?PrivacyFloor $platformFloor = null): PanelDefinition
    {
        $this->assertMutable();

        if (! $this->id instanceof PanelId) {
            throw PanelException::invalidConfig('PANEL_ID_INVALID', 'PanelBuilder requires a valid PanelId.');
        }

        if ($this->hostTypes === []) {
            throw PanelException::invalidConfig('PANEL_HOST_INVALID', "Panel [{$this->id->value}] requires at least one hostType.", ['panel' => $this->id->value]);
        }

        $effectiveFloor = $this->floor ?? ($platformFloor ?? PrivacyFloor::defaultDirect());

        if ($platformFloor instanceof PrivacyFloor && $this->floor instanceof PrivacyFloor) {
            $effectiveFloor = $platformFloor->narrowedBy($this->floor);
        }

        $storage = new PanelStorage(
            connection: $this->connection,
            schema: $this->schema,
            prefix: $this->prefix,
            tables: $this->tables,
            models: $this->models,
        );

        $this->frozen = true;

        return new PanelDefinition(
            id: $this->id,
            hostTypes: $this->hostTypes,
            storage: $storage,
            modules: $this->modules,
            moduleSettings: $this->moduleSettings,
            floor: $effectiveFloor,
            provider: $this->providerClass,
        );
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw FrozenDefinitionException::forBuilder(self::class);
        }
    }
}
