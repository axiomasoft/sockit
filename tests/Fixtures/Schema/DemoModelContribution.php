<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaForeignKey;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

/** Test-only target/revision persistence, independent of production social modules. */
final class DemoModelContribution implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        return new SchemaComponent(id: 'acme.demo-targets', version: 1, steps: [...new DemoTargetsV1()->component()->steps,
            new SchemaStep(id: 'create_demo_revisions', version: 1, entity: 'demo_revisions', columns: [
                'id' => new SchemaColumn('uuid'), 'target_id' => new SchemaColumn('uuid'), 'revision' => new SchemaColumn('bigint'),
                'payload' => new SchemaColumn('json'), 'created_at' => new SchemaColumn('timestamp')],
                model: new SchemaModel(name: 'DemoRevision', casts: ['revision' => 'integer', 'payload' => 'array', 'created_at' => 'immutable_datetime']),
                indexes: ['revision' => new SchemaIndex(columns: ['target_id', 'revision'], unique: true)],
                foreignKeys: ['target' => new SchemaForeignKey(columns: ['target_id'], entity: 'demo_targets', references: ['id'])]),
        ]);
    }
}
