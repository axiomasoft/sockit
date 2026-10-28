<?php

declare(strict_types=1);

namespace SocKit\Schema;

use SocKit\Attributes\Api;
use SocKit\Schema\Internal\SchemaValues;

#[Api(since: '1.0')]
final readonly class SchemaForeignKey
{
    /** @var list<string> */
    public array $columns;

    /** @var list<string> */
    public array $references;

    /** @param list<string> $columns
     * @param list<string> $references */
    public function __construct(array $columns, public string $entity, array $references, public string $onDelete = 'restrict')
    {
        SchemaValues::require(valid: SchemaValues::identifier($entity) && count($columns) === count($references), key: 'foreign_key');
        SchemaValues::require(valid: in_array($onDelete, ['restrict', 'cascade', 'set_null'], true), key: 'foreign_key.on_delete');
        $this->columns = new SchemaIndex($columns)->columns;
        $this->references = new SchemaIndex($references)->columns;
    }
}
