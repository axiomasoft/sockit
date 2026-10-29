<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaForeignKey;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

final class DependentContribution implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        return new SchemaComponent(id: 'acme.demo-links', version: 1, dependencies: ['acme.demo-targets' => 1], steps: [
            new SchemaStep(id: 'create_demo_links', version: 1, entity: 'demo_links', columns: ['id' => new SchemaColumn('uuid'), 'target_id' => new SchemaColumn('uuid')],
                model: new SchemaModel('DemoLink'), foreignKeys: ['target' => new SchemaForeignKey(columns: ['target_id'], entity: 'demo_targets', references: ['id'])]),
        ]);
    }
}
