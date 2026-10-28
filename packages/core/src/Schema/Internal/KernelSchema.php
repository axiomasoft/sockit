<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use SocKit\Schema\SchemaCheck;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

final class KernelSchema
{
    public const string ID = 'sockit.kernel';

    public static function component(): SchemaComponent
    {
        $identity = ['installation' => new SchemaColumn(type: 'string', length: 64, collation: 'C'),
            'panel' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
            'kind' => new SchemaColumn('text'), 'host_type' => new SchemaColumn('text'), 'host_key' => new SchemaColumn('text')];
        $identityKey = array_keys($identity);
        $positive = new SchemaColumn('bigint');
        $date = new SchemaColumn('timestamp');
        $id = new SchemaColumn('uuid');

        return new SchemaComponent(id: self::ID, version: 1, steps: [
            new SchemaStep(id: 'create_surfaces', version: 1, entity: 'surface_instances', columns: [...$identity,
                'id' => $id, 'incarnation' => $positive, 'lifecycle' => new SchemaColumn('text'), 'revision' => $positive,
                'source_generation' => $positive, 'privacy_generation' => $positive,
                'created_at' => $date, 'updated_at' => $date, 'deleted_at' => new SchemaColumn(type: 'timestamp', nullable: true)],
                model: new SchemaModel(name: 'SurfaceInstance', casts: ['incarnation' => 'integer', 'revision' => 'integer', 'source_generation' => 'integer', 'privacy_generation' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'], timestamps: true),
                indexes: ['identity' => new SchemaIndex(columns: $identityKey, unique: true)], checks: [...self::positive(['incarnation', 'revision', 'source_generation', 'privacy_generation']),
                    'lifecycle' => new SchemaCheck(kind: 'one_of', columns: ['lifecycle'], values: ['live', 'archived', 'deleted']),
                    'deleted' => new SchemaCheck(kind: 'nonnull_iff', columns: ['deleted_at', 'lifecycle'], values: ['deleted'])]),
            new SchemaStep(id: 'create_receipts', version: 1, entity: 'receipts', columns: [
                'id' => $id, 'namespace' => new SchemaColumn(type: 'string', length: 384, collation: 'C'),
                'command_key' => new SchemaColumn(type: 'string', length: 128, collation: 'C'),
                'digest' => new SchemaColumn('text'), 'actor_ref' => new SchemaColumn('text'), 'actor_kind' => new SchemaColumn('text'),
                'on_behalf_of' => new SchemaColumn(type: 'text', nullable: true), 'guard_context' => new SchemaColumn('text'),
                'outcome' => new SchemaColumn(type: 'text', nullable: true), 'resource' => new SchemaColumn(type: 'json', nullable: true),
                'revision' => new SchemaColumn(type: 'bigint', nullable: true), 'problem' => new SchemaColumn(type: 'text', nullable: true)],
                model: new SchemaModel(name: 'Receipt', casts: ['resource' => 'array', 'revision' => 'integer']),
                indexes: ['command' => new SchemaIndex(columns: ['namespace', 'command_key'], unique: true)],
                checks: [...self::positive(['revision']), 'outcome' => new SchemaCheck(kind: 'one_of', columns: ['outcome'], values: ['confirmed', 'rejected'])]),
            new SchemaStep(id: 'create_outbox', version: 1, entity: 'outbox', columns: [...$identity,
                'id' => $id, 'incarnation' => $positive, 'source_generation' => new SchemaColumn(type: 'string', length: 64, collation: 'C'),
                'event_id' => $id, 'digest' => new SchemaColumn('text'), 'envelope' => new SchemaColumn('text'), 'deliveries' => new SchemaColumn('json')],
                model: new SchemaModel(name: 'OutboxEvent', casts: ['incarnation' => 'integer', 'deliveries' => 'array']),
                indexes: ['event' => new SchemaIndex(columns: ['installation', 'panel', 'source_generation', 'event_id'], unique: true)], checks: self::positive(['incarnation'])),
            new SchemaStep(id: 'create_tombstones', version: 1, entity: 'tombstones', columns: [...$identity,
                'incarnation' => $positive, 'surface_id' => $id, 'max_revision' => $positive, 'source_generation' => $positive, 'deleted_at' => $date],
                model: new SchemaModel(name: 'Tombstone', primary: [...$identityKey, 'incarnation'], casts: ['incarnation' => 'integer', 'max_revision' => 'integer', 'source_generation' => 'integer', 'deleted_at' => 'immutable_datetime']), checks: self::positive(['incarnation', 'max_revision', 'source_generation'])),
        ]);
    }

    /** @param list<string> $columns
     * @return array<string, SchemaCheck> */
    private static function positive(array $columns): array
    {
        $checks = [];
        foreach ($columns as $column) {
            $checks[$column.'_positive'] = new SchemaCheck(kind: 'positive', columns: [$column]);
        }

        return $checks;
    }
}
