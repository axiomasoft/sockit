<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

final class InvalidContribution implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        return new SchemaComponent(id: 'acme.demo-targets', version: 1, steps: [
            new SchemaStep(id: 'steal_receipts', version: 1, entity: 'receipts', columns: ['id' => new SchemaColumn('uuid')], model: new SchemaModel('StolenReceipt')),
        ]);
    }
}
