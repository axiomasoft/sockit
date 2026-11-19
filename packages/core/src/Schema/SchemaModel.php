<?php

declare(strict_types=1);

namespace SocKit\Schema;

use Packman\Attributes\Api;
use SocKit\Schema\Internal\SchemaValues;

#[Api(since: '1.0')]
final readonly class SchemaModel
{
    /** @var list<string> */
    public array $primary;

    /** @var array<string, string> */
    public array $casts;

    /** Composite keys deliberately remain composite persistence specifications.
     * @param list<string> $primary
     * @param array<string, string> $casts */
    public function __construct(public string $name, array $primary = ['id'], array $casts = [], public string $keyType = 'string', public bool $incrementing = false, public bool $timestamps = false)
    {
        SchemaValues::require(valid: preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) === 1, key: 'model.name');
        SchemaValues::require(valid: in_array($keyType, ['string', 'int'], true) && (! $incrementing || ($keyType === 'int' && count($primary) === 1)), key: 'model.key');
        $this->primary = new SchemaIndex($primary)->columns;
        $copy = [];
        foreach ($casts as $column => $cast) {
            SchemaValues::require(valid: SchemaValues::identifier($column) && in_array($cast, ['string', 'integer', 'boolean', 'array', 'immutable_datetime'], true), key: 'model.casts');
            $copy[$column] = $cast;
        }
        ksort($copy);
        $this->casts = $copy;
    }
}
