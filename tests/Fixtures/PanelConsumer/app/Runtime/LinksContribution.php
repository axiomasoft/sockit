<?php

declare(strict_types=1);

namespace Acme\Runtime;

use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaForeignKey;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

final class LinksContribution implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        return new SchemaComponent(id: 'acme.demo-links', version: 2, steps: [
            new SchemaStep(id: 'create_parents', version: 1, entity: 'demo_parents', columns: ['id' => new SchemaColumn('uuid'), 'name' => new SchemaColumn('text')], model: new SchemaModel('DemoParent')),
            new SchemaStep(id: 'create_children', version: 1, entity: 'demo_children', columns: ['id' => new SchemaColumn('uuid'), 'parent_id' => new SchemaColumn('uuid')], model: new SchemaModel('DemoChild'),
                foreignKeys: ['parent' => new SchemaForeignKey(columns: ['parent_id'], entity: 'demo_parents', references: ['id'])]),
            new SchemaStep(id: 'add_parent_label', version: 2, entity: 'demo_parents', columns: ['label' => new SchemaColumn(type: 'text', nullable: true)]),
        ]);
    }
}
