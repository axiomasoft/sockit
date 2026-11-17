<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use SocKit\Database\SockitMigration;

return new class extends SockitMigration
{
    public function up(): void
    {
        $this->create('reactions', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('panel_id', 96);
            $table->uuid('surface_instance_id');
            $table->string('target_type', 96);
            $table->string('target_key', 512);
            $table->string('actor_type', 96);
            $table->string('actor_id', 512);
            $table->string('reaction_type', 64);
            $table->timestampTz('created_at', 6)->useCurrent();

            $this->foreign($table, 'surface_instance_id')
                ->references('id')
                ->on($this->table('surface_instances'))
                ->onDelete('restrict');
            $this->unique($table, ['panel_id', 'surface_instance_id', 'target_type', 'target_key', 'actor_type', 'actor_id', 'reaction_type']);
            $this->index($table, ['panel_id', 'surface_instance_id', 'target_type', 'target_key']);
        });

        $counts = $this->create('reaction_counts', function (Blueprint $table): void {
            $table->string('panel_id', 96);
            $table->uuid('surface_instance_id');
            $table->string('target_type', 96);
            $table->string('target_key', 512);
            $table->string('reaction_type', 64);
            $table->unsignedBigInteger('count');
            $table->unsignedBigInteger('source_revision');

            $table->primary(['panel_id', 'surface_instance_id', 'target_type', 'target_key', 'reaction_type']);
            $this->foreign($table, 'surface_instance_id')
                ->references('id')
                ->on($this->table('surface_instances'))
                ->onDelete('restrict');
        });
        $this->addCheckIfPostgres($counts, 'count_non_negative', 'count >= 0');
        $this->addCheckIfPostgres($counts, 'source_revision_positive', 'source_revision >= 1');
    }

    public function down(): void
    {
        $this->drop('reaction_counts');
        $this->drop('reactions');
    }
};
