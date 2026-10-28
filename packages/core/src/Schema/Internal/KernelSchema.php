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
            'kind' => new SchemaColumn(type: 'string', length: 96, collation: 'C'), 'host_type' => new SchemaColumn(type: 'string', length: 96, collation: 'C'), 'host_key' => new SchemaColumn(type: 'string', length: 512, collation: 'C')];
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
                indexes: ['identity' => new SchemaIndex(columns: $identityKey, unique: true)], checks: [...self::identityChecks(), ...self::uuidChecks(['id']), ...self::positive(['incarnation', 'revision', 'source_generation', 'privacy_generation']),
                    'lifecycle' => new SchemaCheck(kind: 'one_of', columns: ['lifecycle'], values: ['live', 'archived', 'deleted']),
                    'deleted' => new SchemaCheck(kind: 'nonnull_iff', columns: ['deleted_at', 'lifecycle'], values: ['deleted'])]),
            new SchemaStep(id: 'create_receipts', version: 1, entity: 'receipts', columns: [
                'id' => $id, 'namespace' => new SchemaColumn(type: 'string', length: 384, collation: 'C'),
                'command_key' => new SchemaColumn(type: 'string', length: 128, collation: 'C'),
                'digest' => new SchemaColumn(type: 'string', length: 71, collation: 'C'), 'actor_ref' => new SchemaColumn(type: 'string', length: 609, collation: 'C'), 'actor_kind' => new SchemaColumn(type: 'string', length: 16, collation: 'C'),
                'on_behalf_of' => new SchemaColumn(type: 'string', length: 609, nullable: true, collation: 'C'), 'guard_context' => new SchemaColumn('text'),
                'outcome' => new SchemaColumn(type: 'text', nullable: true), 'resource' => new SchemaColumn(type: 'json', nullable: true),
                'revision' => new SchemaColumn(type: 'bigint', nullable: true), 'problem' => new SchemaColumn(type: 'text', nullable: true)],
                model: new SchemaModel(name: 'Receipt', casts: ['resource' => 'array', 'revision' => 'integer']),
                indexes: ['command' => new SchemaIndex(columns: ['namespace', 'command_key'], unique: true)],
                checks: [...self::uuidChecks(['id']), ...self::positive(['revision']), 'outcome' => new SchemaCheck(kind: 'one_of', columns: ['outcome'], values: ['confirmed', 'rejected']),
                    'actor_kind' => new SchemaCheck(kind: 'one_of', columns: ['actor_kind'], values: ['person', 'service', 'system']),
                    'digest' => new SchemaCheck(kind: 'matches', columns: ['digest'], values: ['^sha256:[0-9a-f]{64}$']),
                    'command_key' => new SchemaCheck(kind: 'matches', columns: ['command_key'], values: ['^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$']),
                    'resource_shape' => new SchemaCheck(kind: 'json_object', columns: ['resource']),
                    'resource_outcome' => new SchemaCheck(kind: 'nonnull_iff', columns: ['resource', 'outcome'], values: ['confirmed']),
                    'revision_outcome' => new SchemaCheck(kind: 'nonnull_iff', columns: ['revision', 'outcome'], values: ['confirmed']),
                    'problem_outcome' => new SchemaCheck(kind: 'nonnull_iff', columns: ['problem', 'outcome'], values: ['rejected']),
                    ...self::bounds(['namespace' => 384, 'actor_ref' => 609, 'on_behalf_of' => 609])]),
            new SchemaStep(id: 'create_outbox', version: 1, entity: 'outbox', columns: [...$identity,
                'id' => $id, 'incarnation' => $positive, 'source_generation' => new SchemaColumn(type: 'string', length: 64, collation: 'C'),
                'event_id' => $id, 'digest' => new SchemaColumn(type: 'string', length: 71, collation: 'C'), 'envelope' => new SchemaColumn('text'), 'deliveries' => new SchemaColumn('json')],
                model: new SchemaModel(name: 'OutboxEvent', casts: ['incarnation' => 'integer', 'deliveries' => 'array']),
                indexes: ['event' => new SchemaIndex(columns: ['installation', 'panel', 'source_generation', 'event_id'], unique: true),
                    'relay' => new SchemaIndex([...$identityKey, 'incarnation', 'event_id'])],
                checks: [...self::identityChecks(), ...self::uuidChecks(['id', 'event_id']), ...self::positive(['incarnation']),
                    ...self::bounds(['source_generation' => 64]),
                    'digest' => new SchemaCheck(kind: 'matches', columns: ['digest'], values: ['^sha256:[0-9a-f]{64}$']),
                    'deliveries_object' => new SchemaCheck(kind: 'json_object', columns: ['deliveries'])]),
            new SchemaStep(id: 'create_tombstones', version: 1, entity: 'tombstones', columns: [...$identity,
                'incarnation' => $positive, 'surface_id' => $id, 'max_revision' => $positive, 'source_generation' => $positive, 'deleted_at' => $date],
                model: new SchemaModel(name: 'Tombstone', primary: [...$identityKey, 'incarnation'], casts: ['incarnation' => 'integer', 'max_revision' => 'integer', 'source_generation' => 'integer', 'deleted_at' => 'immutable_datetime']), checks: [...self::identityChecks(), ...self::uuidChecks(['surface_id']), ...self::positive(['incarnation', 'max_revision', 'source_generation'])]),
        ]);
    }

    /** Production baseline v1 is frozen by generated files, never consulted at migration execution.
     * @return array<string, SchemaCheck> */
    private static function identityChecks(): array
    {
        return self::bounds(['installation' => 64, 'panel' => 96, 'kind' => 96, 'host_type' => 96, 'host_key' => 512]);
    }

    /** @param array<string, int> $bounds
     * @return array<string, SchemaCheck> */
    private static function bounds(array $bounds): array
    {
        $checks = [];
        foreach ($bounds as $column => $maximum) {
            $checks[$column.'_bytes'] = new SchemaCheck(kind: 'byte_length', columns: [$column], values: [(string) $maximum]);
        }

        return $checks;
    }

    /** @param list<string> $columns
     * @return array<string, SchemaCheck> */
    private static function uuidChecks(array $columns): array
    {
        $checks = [];
        foreach ($columns as $column) {
            $checks[$column.'_uuid7'] = new SchemaCheck(kind: 'matches', columns: [$column], values: ['^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$']);
        }

        return $checks;
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
