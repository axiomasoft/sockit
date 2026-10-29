<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Illuminate\Contracts\Config\Repository;
use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaCheck;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaForeignKey;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

final readonly class UpgradeVariant implements SchemaContribution
{
    public function __construct(private Repository $config) {}

    public function component(): SchemaComponent
    {
        $base = new DemoTargetsV1()->component();
        $variant = $this->config->get('schema_fixture.variant');
        if ($variant === 'rewrite') {
            $step = $base->steps[0];

            return new SchemaComponent(id: $base->id, version: 2, steps: [new SchemaStep(id: $step->id, version: 1, entity: $step->entity,
                columns: [...$step->columns, 'external_key' => new SchemaColumn(type: 'string', length: 8)], model: $step->model, indexes: $step->indexes),
                new SchemaStep(id: 'add_label', version: 2, entity: 'demo_targets', columns: ['label' => new SchemaColumn(type: 'text', nullable: true)])]);
        }
        if ($variant === 'new_fk') {
            return new SchemaComponent(id: $base->id, version: 2, steps: [...$base->steps,
                new SchemaStep(id: 'create_notes', version: 2, entity: 'demo_notes', columns: ['id' => new SchemaColumn('uuid')], model: new SchemaModel('DemoNote')),
                new SchemaStep(id: 'link_notes', version: 2, entity: 'demo_targets', columns: ['parent_id' => new SchemaColumn(type: 'uuid', nullable: true)],
                    foreignKeys: ['parent' => new SchemaForeignKey(columns: ['parent_id'], entity: 'demo_notes', references: ['id'])]),
            ]);
        }
        $step = match ($variant) {
            'required' => new SchemaStep(id: 'add_required', version: 2, entity: 'demo_targets', columns: ['label' => new SchemaColumn('text')]),
            'default' => new SchemaStep(id: 'add_default', version: 2, entity: 'demo_targets', columns: ['label' => new SchemaColumn(type: 'text', default: 'default-label', hasDefault: true)]),
            'unique' => new SchemaStep(id: 'add_unique', version: 2, entity: 'demo_targets', columns: [], indexes: ['global_external' => new SchemaIndex(columns: ['external_key'], unique: true)]),
            'check' => new SchemaStep(id: 'add_check', version: 2, entity: 'demo_targets', columns: [], checks: ['allowed_external' => new SchemaCheck(kind: 'one_of', columns: ['external_key'], values: ['allowed'])]),
            'fk' => new SchemaStep(id: 'add_fk', version: 2, entity: 'demo_targets', columns: ['parent_id' => new SchemaColumn(type: 'uuid', nullable: true)],
                foreignKeys: ['parent' => new SchemaForeignKey(columns: ['parent_id'], entity: 'demo_targets', references: ['id'])]),
            'json_fk' => new SchemaStep(id: 'add_metadata_parent', version: 2, entity: 'demo_targets', columns: [
                'metadata' => new SchemaColumn(type: 'json', nullable: true), 'parent_id' => new SchemaColumn(type: 'uuid', nullable: true)],
                foreignKeys: ['parent' => new SchemaForeignKey(columns: ['parent_id'], entity: 'demo_targets', references: ['id'])]),
            default => throw new \RuntimeException('Unknown upgrade variant'),
        };

        return new SchemaComponent(id: $base->id, version: 2, steps: [...$base->steps, $step]);
    }
}
