<?php

declare(strict_types=1);

namespace SocKit\Reactions\Internal;

use Packman\Attributes\Internal;
use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaCheck;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

/** Append-only v1. Existing snapshots never call this class at migration time. */
#[Internal]
final class InitialSchema implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        $target = [...$this->surface(),
            'target_type' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
            'target_key' => new SchemaColumn(type: 'text', collation: 'C'),
            'target_incarnation' => new SchemaColumn('bigint'),
            'reaction_kind' => new SchemaColumn(type: 'string', length: 32, collation: 'C'),
            'identity_digest' => new SchemaColumn(type: 'string', length: 64, collation: 'C')];
        $checks = [...$this->bounds(), ...$this->uuid(), ...$this->positive(['surface_incarnation', 'target_incarnation', 'revision']),
            'digest' => new SchemaCheck(kind: 'matches', columns: ['identity_digest'], values: ['^[0-9a-f]{64}$']),
            'target_len' => new SchemaCheck(kind: 'byte_length', columns: ['target_key'], values: ['512']),
            'target_alias' => new SchemaCheck(kind: 'matches', columns: ['target_type'], values: ['^[a-z][a-z0-9_.-]{0,95}$']),
            'kind' => new SchemaCheck(kind: 'matches', columns: ['reaction_kind'], values: ['^[a-z][a-z0-9_-]{0,31}$'])];
        $dates = ['created_at' => new SchemaColumn('timestamp'), 'updated_at' => new SchemaColumn('timestamp')];

        return new SchemaComponent(id: 'sockit.reactions', version: 1, steps: [
            new SchemaStep(id: 'create_reactions', version: 1, entity: 'reactions', columns: [...$target,
                'id' => new SchemaColumn('uuid'), 'actor_ref' => new SchemaColumn(type: 'text', collation: 'C'),
                'active' => new SchemaColumn('boolean'), 'revision' => new SchemaColumn('bigint'), ...$dates],
                model: new SchemaModel(name: 'Reaction', casts: ['active' => 'boolean', 'revision' => 'integer'], timestamps: true),
                indexes: ['membership' => new SchemaIndex(columns: ['identity_digest'], unique: true)],
                checks: [...$checks, 'actor_len' => new SchemaCheck(kind: 'byte_length', columns: ['actor_ref'], values: ['609'])]),
            new SchemaStep(id: 'create_reaction_counts', version: 1, entity: 'reaction_counts', columns: [...$target,
                'id' => new SchemaColumn('uuid'), 'count' => new SchemaColumn('bigint'), 'revision' => new SchemaColumn('bigint'), ...$dates],
                model: new SchemaModel(name: 'ReactionCount', casts: ['count' => 'integer', 'revision' => 'integer'], timestamps: true),
                indexes: ['target_kind' => new SchemaIndex(columns: ['identity_digest'], unique: true)],
                // Existing bounded predicate expresses >=0 without extending the schema API.
                checks: [...$checks, 'nonnegative' => new SchemaCheck(kind: 'matches', columns: ['count'], values: ['^[0-9]+$'])]),
        ]);
    }

    /** Full canonical refs are retained; only bounded digests enter wide identity indexes.
     * @return array<string, SchemaColumn> */
    private function surface(): array
    {
        return [
            'installation' => new SchemaColumn(type: 'string', length: 64, collation: 'C'),
            'panel' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
            'surface_kind' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
            'host_type' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
            'host_key' => new SchemaColumn(type: 'text', collation: 'C'),
            'surface_incarnation' => new SchemaColumn('bigint'),
        ];
    }

    /** @param list<string> $columns
     * @return array<string, SchemaCheck> */
    private function positive(array $columns): array
    {
        $checks = [];
        foreach ($columns as $column) {
            $checks[$column.'_pos'] = new SchemaCheck(kind: 'positive', columns: [$column]);
        }

        return $checks;
    }

    /** @return array<string, SchemaCheck> */
    private function bounds(): array
    {
        $checks = [];
        foreach (['installation' => 64, 'panel' => 96, 'surface_kind' => 96, 'host_type' => 96, 'host_key' => 512] as $column => $limit) {
            $checks[$column.'_len'] = new SchemaCheck(kind: 'byte_length', columns: [$column], values: [(string) $limit]);
        }

        return $checks;
    }

    /** @return array<string, SchemaCheck> */
    private function uuid(): array
    {
        return ['uuid' => new SchemaCheck(kind: 'matches', columns: ['id'], values: ['^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'])];
    }
}
