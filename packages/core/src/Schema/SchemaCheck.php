<?php

declare(strict_types=1);

namespace SocKit\Schema;

use SocKit\Attributes\Api;
use SocKit\Schema\Internal\SchemaValues;

/** Bounded data predicates, never executable SQL supplied by a module. */
#[Api(since: '1.0')]
final readonly class SchemaCheck
{
    /** @var list<string> */
    public array $columns;

    /** @var list<string> */
    public array $values;

    /** positive: nullable or >= 1; one_of: nullable or listed value;
     * nonnull_iff: first column is nonnull iff second equals the sole value.
     *
     * @param list<string> $columns
     * @param list<string> $values */
    public function __construct(public string $kind, array $columns, array $values = [])
    {
        SchemaValues::require(valid: in_array($kind, ['positive', 'one_of', 'nonnull_iff'], true), key: 'check.kind');
        $this->columns = new SchemaIndex($columns)->columns;
        SchemaValues::require(valid: count($columns) === ($kind === 'nonnull_iff' ? 2 : 1), key: 'check.columns');
        SchemaValues::require(valid: $kind === 'positive' ? $values === [] : $values !== [], key: 'check.values');
        SchemaValues::require(valid: $kind !== 'nonnull_iff' || count($values) === 1, key: 'check.values');
        $copy = [];
        foreach ($values as $value) {
            $copy[] = $value;
        }
        sort($copy);
        SchemaValues::require(valid: count(array_unique($copy)) === count($copy), key: 'check.values');
        $this->values = $copy;
    }
}
