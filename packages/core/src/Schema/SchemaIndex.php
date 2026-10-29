<?php

declare(strict_types=1);

namespace SocKit\Schema;

use SocKit\Attributes\Api;
use SocKit\Schema\Internal\SchemaValues;

#[Api(since: '1.0')]
final readonly class SchemaIndex
{
    /** @var list<string> */
    public array $columns;

    /** @param array<array-key, string> $columns */
    public function __construct(array $columns, public bool $unique = false)
    {
        SchemaValues::require(valid: array_is_list($columns) && $columns !== [] && count(array_unique($columns)) === count($columns), key: 'index.columns');
        $copy = [];
        foreach ($columns as $column) {
            SchemaValues::require(valid: SchemaValues::identifier($column), key: 'index.columns');
            $copy[] = $column;
        }
        $this->columns = $copy;
    }
}
