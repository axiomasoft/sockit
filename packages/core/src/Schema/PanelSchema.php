<?php

declare(strict_types=1);

namespace SocKit\Schema;

use SocKit\Attributes\Api;
use SocKit\Panels\PanelStorage;
use SocKit\Schema\Internal\SchemaValues;

/** Data-only draft descriptor. Concrete binding/install validation belongs to the installer. */
#[Api(since: '1.0')]
final readonly class PanelSchema implements \JsonSerializable
{
    public const int FORMAT_VERSION = 1;

    public string $digest;

    private string $descriptor;

    /** @param array<string, mixed> $components
     * @param array<string, mixed> $entities
     * @param list<string> $order */
    public function __construct(public string $panel, public PanelStorage $storage, array $components, array $entities, array $order)
    {
        $this->descriptor = SchemaValues::json(['format_version' => self::FORMAT_VERSION, 'mode' => 'draft_generation', 'panel' => $panel,
            'connection' => $storage->connection, 'schema' => $storage->schema, 'prefix' => $storage->prefix, 'ids' => $storage->ids,
            'tables' => $storage->tables, 'models' => $storage->models,
            'components' => $components, 'entities' => $entities, 'dependency_order' => $order]);
        $this->digest = hash('sha256', $this->descriptor);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $value = json_decode($this->descriptor, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($value));

        /** @var array<string, mixed> $value */
        return [...$value, 'digest' => $this->digest];
    }

    public function toJson(): string
    {
        return SchemaValues::json($this->jsonSerialize());
    }
}
