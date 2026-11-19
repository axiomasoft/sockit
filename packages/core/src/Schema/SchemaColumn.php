<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Packman\Attributes\Api;
use SocKit\Schema\Internal\SchemaValues;

#[Api(since: '1.0')]
final readonly class SchemaColumn
{
    public function __construct(public string $type, public bool $nullable = false, public ?int $length = null, public string|int|bool|null $default = null, public bool $hasDefault = false, public ?string $collation = null)
    {
        SchemaValues::require(valid: in_array($type, ['uuid', 'string', 'text', 'bigint', 'integer', 'boolean', 'json', 'timestamp'], true), key: 'column.type');
        SchemaValues::require(valid: $type === 'string' ? $length !== null && $length > 0 : $length === null, key: 'column.length');
        SchemaValues::require(valid: $collation === null || ($collation === 'C' && in_array($type, ['string', 'text'], true)), key: 'column.collation');
        SchemaValues::require(valid: $hasDefault || $default === null, key: 'column.default');
        if ($hasDefault) {
            SchemaValues::require(valid: $default === null ? $nullable : match ($type) {
                'boolean' => is_bool($default),
                'bigint', 'integer' => is_int($default),
                'text', 'string' => is_string($default) && ($length === null || strlen($default) <= $length),
                default => false,
            }, key: 'column.default');
        }
    }

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'nullable' => $this->nullable, 'length' => $this->length, 'default' => $this->default, 'hasDefault' => $this->hasDefault, 'collation' => $this->collation];
    }
}
