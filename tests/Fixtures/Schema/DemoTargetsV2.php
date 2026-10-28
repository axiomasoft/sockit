<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaStep;

final class DemoTargetsV2 implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        return new SchemaComponent(id: 'acme.demo-targets', version: 2, steps: [...new DemoTargetsV1()->component()->steps,
            new SchemaStep(id: 'add_demo_label', version: 2, entity: 'demo_targets', columns: ['label' => new SchemaColumn(type: 'text', nullable: true)],
                indexes: ['label' => new SchemaIndex(['label'])]),
        ]);
    }
}
