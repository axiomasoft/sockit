<?php

declare(strict_types=1);

namespace SocKit\Posts\Internal;

use SocKit\Schema\Contracts\SchemaContribution;
use SocKit\Schema\SchemaCheck;
use SocKit\Schema\SchemaColumn;
use SocKit\Schema\SchemaComponent;
use SocKit\Schema\SchemaForeignKey;
use SocKit\Schema\SchemaIndex;
use SocKit\Schema\SchemaModel;
use SocKit\Schema\SchemaStep;

/** Append-only v1. Existing snapshots never call this class at migration time. */
final class InitialSchema implements SchemaContribution
{
    public function component(): SchemaComponent
    {
        $states = ['pending', 'published', 'held', 'rejected'];
        $publication = new SchemaColumn(type: 'string', length: 16, collation: 'C');
        $json = new SchemaColumn('json');
        $revision = new SchemaColumn('bigint');

        return new SchemaComponent(id: 'sockit.posts', version: 2, steps: [
            new SchemaStep(id: 'create_posts', version: 1, entity: 'posts', columns: [...$this->surface(),
                'id' => new SchemaColumn('uuid'), 'surface_digest' => new SchemaColumn(type: 'string', length: 64, collation: 'C'),
                'author_ref' => new SchemaColumn(type: 'text', collation: 'C'),
                'body' => new SchemaColumn(type: 'text', nullable: true), 'media_refs' => $json, 'audience' => $json,
                'revision' => $revision, 'privacy_revision' => $revision, 'publication_state' => $publication,
                // Overlay never replaces the previous publication state, including Pending/Held.
                'visibility' => new SchemaColumn(type: 'string', length: 16, collation: 'C'),
                'content_state' => new SchemaColumn(type: 'string', length: 16, collation: 'C'),
                'safety_generation' => $revision, 'consent_generation' => $revision,
                'published_at' => new SchemaColumn(type: 'timestamp', nullable: true),
                'erased_at' => new SchemaColumn(type: 'timestamp', nullable: true),
                'created_at' => new SchemaColumn('timestamp'), 'updated_at' => new SchemaColumn('timestamp')],
                model: new SchemaModel(name: 'Post', casts: ['audience' => 'array', 'media_refs' => 'array', 'revision' => 'integer', 'privacy_revision' => 'integer'], timestamps: true),
                indexes: ['chronology' => new SchemaIndex(['surface_digest', 'published_at', 'id'])],
                checks: [...$this->bounds(), ...$this->uuid(), ...$this->positive(['surface_incarnation', 'revision', 'privacy_revision', 'safety_generation', 'consent_generation']),
                    'surface_digest' => new SchemaCheck(kind: 'matches', columns: ['surface_digest'], values: ['^[0-9a-f]{64}$']),
                    'author_len' => new SchemaCheck(kind: 'byte_length', columns: ['author_ref'], values: ['609']),
                    'publication' => new SchemaCheck(kind: 'one_of', columns: ['publication_state'], values: $states),
                    'visibility' => new SchemaCheck(kind: 'one_of', columns: ['visibility'], values: ['visible', 'hidden', 'deleted']),
                    'content' => new SchemaCheck(kind: 'one_of', columns: ['content_state'], values: ['retained', 'erased']),
                    // Media-only retained posts use an empty string. Erasure removes the body.
                    'body_retained' => new SchemaCheck(kind: 'nonnull_iff', columns: ['body', 'content_state'], values: ['retained']),
                    'erased' => new SchemaCheck(kind: 'nonnull_iff', columns: ['erased_at', 'content_state'], values: ['erased']),
                    'audience_object' => new SchemaCheck(kind: 'json_object', columns: ['audience'])]),
            new SchemaStep(id: 'create_post_revisions', version: 1, entity: 'post_revisions', columns: [
                'id' => new SchemaColumn('uuid'), 'post_id' => new SchemaColumn('uuid'), 'revision' => $revision,
                'actor_ref' => new SchemaColumn(type: 'text', collation: 'C'), 'reason' => new SchemaColumn(type: 'text', nullable: true),
                'action' => new SchemaColumn(type: 'string', length: 32, collation: 'C'),
                'publication_state' => $publication, 'visibility' => new SchemaColumn(type: 'string', length: 16, collation: 'C'),
                'audience' => $json, 'privacy_revision' => $revision,
                'body' => new SchemaColumn(type: 'text', nullable: true), 'media_refs' => $json,
                'created_at' => new SchemaColumn('timestamp')],
                model: new SchemaModel(name: 'PostRevision', casts: ['audience' => 'array', 'media_refs' => 'array', 'revision' => 'integer', 'privacy_revision' => 'integer']),
                indexes: ['revision' => new SchemaIndex(columns: ['post_id', 'revision'], unique: true)],
                foreignKeys: ['post' => new SchemaForeignKey(columns: ['post_id'], entity: 'posts', references: ['id'])],
                checks: [...$this->uuid(), ...$this->positive(['revision', 'privacy_revision']),
                    'actor_len' => new SchemaCheck(kind: 'byte_length', columns: ['actor_ref'], values: ['609']),
                    'action' => new SchemaCheck(kind: 'one_of', columns: ['action'], values: ['create', 'edit', 'publish', 'hold', 'reject', 'hide', 'restore', 'delete', 'erase', 'audience']),
                    'publication' => new SchemaCheck(kind: 'one_of', columns: ['publication_state'], values: $states),
                    'visibility' => new SchemaCheck(kind: 'one_of', columns: ['visibility'], values: ['visible', 'hidden', 'deleted']),
                    'audience_object' => new SchemaCheck(kind: 'json_object', columns: ['audience'])]),
            new SchemaStep(id: 'add_post_format', version: 2, entity: 'posts', columns: [
                'body_format' => new SchemaColumn(type: 'string', length: 48, default: 'plain', hasDefault: true, collation: 'C'),
            ]),
            new SchemaStep(id: 'add_revision_format', version: 2, entity: 'post_revisions', columns: [
                'body_format' => new SchemaColumn(type: 'string', length: 48, default: 'plain', hasDefault: true, collation: 'C'),
            ]),
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
