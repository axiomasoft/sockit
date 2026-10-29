<?php

declare(strict_types=1);

namespace SocKit\Panels;

use SocKit\Attributes\Api;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\Internal\PanelValues;
use SocKit\Support\Exceptions\InvalidPayloadException;

#[Api(since: '1.0')]
final class Panel
{
    private string $identifier = '';

    /** @var list<string> */
    private array $hosts = [];

    private string $connectionName = 'default';

    private ?string $schemaName = null;

    private string $tablePrefix = '';

    /** @var array<string, string> */
    private array $tableMap = [];

    /** @var array<string, class-string> */
    private array $modelMap = [];

    /** @var list<string> */
    private array $selectedModules = [];

    /** @var array<string, array<string, scalar|array<array-key, mixed>|null>> */
    private array $settings = [];

    private PrivacyFloor $privacyFloor;

    private bool $frozen = false;

    public function __construct(?PrivacyFloor $floor = null, private readonly string $provider = '')
    {
        $this->privacyFloor = $floor ?? new PrivacyFloor(allowedScopes: [Scope::Direct], defaultScope: Scope::Direct, maxScope: Scope::Direct, minorDefaultScope: Scope::Direct, minorMaxScope: Scope::Direct, contourLevels: []);
    }

    public function id(string $id): self
    {
        $this->mutable();
        $this->identifier = $id;

        return $this;
    }

    public function hostTypes(string ...$aliases): self
    {
        $this->mutable();
        $this->hosts = array_values($aliases);

        return $this;
    }

    public function connection(string $name): self
    {
        $this->mutable();
        $this->connectionName = $name;

        return $this;
    }

    public function schema(?string $schema): self
    {
        $this->mutable();
        $this->schemaName = $schema;

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->mutable();
        $this->tablePrefix = $prefix;

        return $this;
    }

    /** Override unprefixed kernel table names; materialize applies prefix exactly once.
     * @param array<string, string> $tables */
    public function tables(array $tables): self
    {
        $this->mutable();
        $this->tableMap = $tables;

        return $this;
    }

    /** @param array<string, class-string> $models */
    public function models(array $models): self
    {
        $this->mutable();
        $this->modelMap = $models;

        return $this;
    }

    public function modules(string ...$ids): self
    {
        $this->mutable();
        $this->selectedModules = array_values($ids);

        return $this;
    }

    /** @param array<string, scalar|array<array-key, mixed>|null> $settings */
    public function moduleSettings(string $module, array $settings): self
    {
        $this->mutable();
        $this->settings[$module] = $settings;

        return $this;
    }

    /** @return list<string> */
    public function selectedModules(): array
    {
        return $this->selectedModules;
    }

    public function floor(PrivacyFloor $floor): self
    {
        $this->mutable();
        $this->privacyFloor = $floor;

        return $this;
    }

    /** @param list<string> $entities Schema-owned keys; direct builders retain the kernel baseline. */
    public function materialize(array $entities = PanelStorage::KERNEL_ENTITIES): PanelDefinition
    {
        $this->mutable();
        $this->frozen = true;
        try {
            $id = new PanelId($this->identifier);
        } catch (InvalidPayloadException $error) {
            throw new InvalidPanelException(message: 'Invalid panel ID', detail: ['panel' => $this->identifier, 'provider' => $this->provider, 'key' => 'id', 'rule' => 'PANEL_ID_INVALID'], previous: $error);
        }
        try {
            $tables = [];
            foreach ($entities as $key) {
                $tables[$key] = $this->tablePrefix.($this->tableMap[$key] ?? $key);
            }
            PanelValues::require(valid: array_diff(array_keys($this->tableMap), $entities) === [], key: 'tables', rule: 'PANEL_TABLE_INVALID');
            PanelValues::require(valid: array_diff(array_keys($this->modelMap), $entities) === [], key: 'models', rule: 'PANEL_MODEL_INVALID');

            return new PanelDefinition(id: $id, hostTypes: $this->hosts,
                storage: new PanelStorage(connection: $this->connectionName, schema: $this->schemaName, prefix: $this->tablePrefix, tables: $tables, models: $this->modelMap),
                modules: $this->selectedModules, moduleSettings: $this->settings, floor: $this->privacyFloor, provider: $this->provider);
        } catch (InvalidPanelException $error) {
            throw new InvalidPanelException(message: $error->getMessage(), detail: array_replace($error->detail(), ['panel' => $this->identifier, 'provider' => $this->provider]), previous: $error);
        }
    }

    private function mutable(): void
    {
        if ($this->frozen) {
            throw new InvalidPanelException(message: 'Panel builder is frozen', detail: ['panel' => $this->identifier, 'provider' => $this->provider, 'key' => 'panel', 'rule' => 'PANEL_REGISTRY_PHASE']);
        }
    }
}
