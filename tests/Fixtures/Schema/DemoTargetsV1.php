<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

/** Test-only owner, never registered by the package. */
final class DemoTargetsV1 implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        return new SchemaComponent(id: 'acme.demo-targets', version: 1, steps: [
            new SchemaStep(id: 'create_demo_targets', version: 1, entity: 'demo_targets', columns: [
                'id' => new SchemaColumn('uuid'), 'installation' => new SchemaColumn(type: 'string', length: 64, collation: 'C'),
                'panel' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
                'external_key' => new SchemaColumn('text'), 'enabled' => new SchemaColumn(type: 'boolean', default: true, hasDefault: true)],
                model: new SchemaModel(name: 'DemoTarget', casts: ['enabled' => 'boolean']),
                indexes: ['external' => new SchemaIndex(columns: ['installation', 'panel', 'external_key'], unique: true)]),
        ]);
    }
}
