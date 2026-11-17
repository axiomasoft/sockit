<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use SocKit\Database\SockitMigration;

return new class extends SockitMigration
{
    public function up(): void
    {
        $posts = $this->create('posts', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('panel_id', 96);
            $table->uuid('surface_instance_id');
            $table->unsignedBigInteger('surface_incarnation');
            $table->string('author_type', 96);
            $table->string('author_id', 512);
            $table->string('state', 32);
            $table->string('body_format', 48);
            $table->text('current_body')->nullable();
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('privacy_revision');
            $table->jsonb('audience_wire');
            $table->timestampTz('published_at', 6)->nullable();
            $table->timestampTz('hidden_at', 6)->nullable();
            $table->timestampTz('deleted_at', 6)->nullable();
            $table->timestampTz('erased_at', 6)->nullable();
            $this->timestampsTz($table);

            $this->foreign($table, 'surface_instance_id')
                ->references('id')
                ->on($this->table('surface_instances'))
                ->onDelete('restrict');
            $this->index($table, ['panel_id', 'surface_instance_id', 'state', 'published_at', 'id']);
        });
        $this->addCheckIfPostgres($posts, 'revision_positive', 'revision >= 1');
        $this->addCheckIfPostgres($posts, 'privacy_revision_positive', 'privacy_revision >= 1');
        $this->addCheckIfPostgres($posts, 'erased_body_null', "(state <> 'erased') OR current_body IS NULL");

        $revisions = $this->create('post_revisions', function (Blueprint $table): void {
            $table->uuid('post_id');
            $table->unsignedBigInteger('revision');
            $table->string('state', 32);
            $table->string('body_format', 48);
            $table->text('body')->nullable();
            $table->jsonb('audience_wire');
            $table->string('actor_type', 96);
            $table->string('actor_id', 512);
            $table->string('reason_code', 96)->nullable();
            $table->timestampTz('recorded_at', 6);

            $table->primary(['post_id', 'revision']);
            $this->foreign($table, 'post_id')
                ->references('id')
                ->on($this->table('posts'))
                ->onDelete('cascade');
        });
        $this->addCheckIfPostgres($revisions, 'revision_positive', 'revision >= 1');
    }

    public function down(): void
    {
        $this->drop('post_revisions');
        $this->drop('posts');
    }
};
