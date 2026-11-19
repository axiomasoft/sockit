<?php

declare(strict_types=1);

namespace SocKit\Groups\Internal;

use Packman\Attributes\Internal;
use SocKit\Groups\Enums\GroupVisibility;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Groups\Enums\MembershipRole;
use SocKit\Groups\Enums\MembershipState;
use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaCheck;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaForeignKey;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

/** Append-only v1. Applied snapshots never call this class at migration time. */
#[Internal]
final class InitialSchema implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        $dates = ['created_at' => new SchemaColumn('timestamp'), 'updated_at' => new SchemaColumn('timestamp')];
        $digest = new SchemaColumn(type: 'string', length: 64, collation: 'C');
        $revision = new SchemaColumn('bigint');

        return new SchemaComponent(id: 'sockit.groups', version: 2, steps: [
            new SchemaStep(id: 'create_groups', version: 1, entity: 'groups', columns: [
                'id' => new SchemaColumn('uuid'),
                'installation' => new SchemaColumn(type: 'string', length: 64, collation: 'C'),
                'panel' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
                'surface_kind' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
                'host_type' => new SchemaColumn(type: 'string', length: 96, collation: 'C'),
                'host_key' => new SchemaColumn(type: 'text', collation: 'C'),
                'surface_incarnation' => $revision, 'surface_digest' => $digest,
                'name' => new SchemaColumn('text'),
                'join_policy' => new SchemaColumn(type: 'string', length: 16, collation: 'C'),
                'revision' => $revision, 'membership_generation' => $revision, ...$dates],
                model: new SchemaModel(name: 'Group', timestamps: true),
                indexes: ['surface' => new SchemaIndex(columns: ['surface_digest'], unique: true)],
                checks: [...$this->uuid(), ...$this->positive(['surface_incarnation', 'revision', 'membership_generation']),
                    ...$this->bounds(['installation' => 64, 'panel' => 96, 'surface_kind' => 96, 'host_type' => 96, 'host_key' => 512, 'name' => 512]),
                    'surface_digest' => $this->digest('surface_digest'),
                    'join_policy' => new SchemaCheck(kind: 'one_of', columns: ['join_policy'], values: array_column(JoinPolicy::cases(), 'value'))]),
            new SchemaStep(id: 'create_group_members', version: 1, entity: 'group_members', columns: [
                'id' => new SchemaColumn('uuid'), 'group_id' => new SchemaColumn('uuid'),
                'actor_ref' => new SchemaColumn(type: 'text', collation: 'C'), 'actor_digest' => $digest,
                'state' => new SchemaColumn(type: 'string', length: 16, collation: 'C'), 'revision' => $revision,
                // An accepted authority fence, never a copy of host consent records or PII.
                'consent_generation' => new SchemaColumn(type: 'bigint', nullable: true),
                'consent_token' => new SchemaColumn(type: 'text', nullable: true, collation: 'C'), ...$dates],
                model: new SchemaModel(name: 'GroupMember', timestamps: true),
                indexes: ['membership' => new SchemaIndex(columns: ['group_id', 'actor_digest'], unique: true)],
                foreignKeys: ['group' => new SchemaForeignKey(columns: ['group_id'], entity: 'groups', references: ['id'])],
                checks: [...$this->uuid(), ...$this->positive(['revision', 'consent_generation']),
                    ...$this->bounds(['actor_ref' => 609, 'consent_token' => 512]),
                    'actor_digest' => $this->digest('actor_digest'),
                    'state' => new SchemaCheck(kind: 'one_of', columns: ['state'], values: array_column(MembershipState::cases(), 'value'))]),
            new SchemaStep(id: 'add_visibility', version: 2, entity: 'groups', columns: [
                'visibility' => new SchemaColumn(type: 'string', length: 16, default: 'public', hasDefault: true, collation: 'C'),
            ], checks: ['visibility' => new SchemaCheck(kind: 'one_of', columns: ['visibility'], values: array_column(GroupVisibility::cases(), 'value'))]),
            new SchemaStep(id: 'add_role', version: 2, entity: 'group_members', columns: [
                'role' => new SchemaColumn(type: 'string', length: 16, default: 'member', hasDefault: true, collation: 'C'),
            ], checks: ['role' => new SchemaCheck(kind: 'one_of', columns: ['role'], values: array_column(MembershipRole::cases(), 'value'))]),
        ]);
    }

    private function digest(string $column): SchemaCheck
    {
        return new SchemaCheck(kind: 'matches', columns: [$column], values: ['^[0-9a-f]{64}$']);
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

    /** @param array<string, int> $bounds
     * @return array<string, SchemaCheck> */
    private function bounds(array $bounds): array
    {
        $checks = [];
        foreach ($bounds as $column => $limit) {
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
