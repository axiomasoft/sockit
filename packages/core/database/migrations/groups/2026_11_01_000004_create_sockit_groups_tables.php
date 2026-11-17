<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use SocKit\Database\SockitMigration;

return new class extends SockitMigration
{
    public function up(): void
    {
        $this->forEachPanelStorage('groups', function (): void {
            $groups = $this->create('groups', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('panel_id', 96);
                $table->string('surface_kind', 96);
                $table->string('surface_host_type', 96);
                $table->string('surface_host_value', 512);
                $table->unsignedBigInteger('surface_incarnation');
                $table->string('slug', 64);
                $table->string('title', 160);
                $table->string('visibility', 32);
                $table->string('join_policy', 32)->default('approval');
                $table->string('state', 32);
                $table->unsignedBigInteger('revision');
                $table->unsignedBigInteger('membership_generation')->default(1);
                $table->string('created_by_type', 96);
                $table->string('created_by_id', 512);
                $this->timestampsTz($table);

                $this->unique($table, ['panel_id', 'slug']);
            });
            $this->addCheckIfPostgres($groups, 'revision_positive', 'revision >= 1');
            $this->addCheckIfPostgres($groups, 'membership_generation_positive', 'membership_generation >= 1');
            $this->addCheckIfPostgres($groups, 'join_policy_valid', "join_policy IN ('auto', 'approval', 'invite', 'closed')");

            $this->create('group_members', function (Blueprint $table): void {
                $table->uuid('group_id');
                $table->string('actor_type', 96);
                $table->string('actor_id', 512);
                $table->string('role', 32);
                $table->string('status', 32);
                $table->string('consent_generation', 64)->nullable();
                $table->timestampTz('joined_at', 6)->nullable();
                $table->timestampTz('updated_at', 6);

                $table->primary(['group_id', 'actor_type', 'actor_id']);
                $this->foreign($table, 'group_id')
                    ->references('id')
                    ->on($this->table('groups'))
                    ->onDelete('cascade');
                $this->index($table, ['actor_type', 'actor_id', 'status']);
            });
        });
    }

    public function down(): void
    {
        $this->forEachPanelStorage('groups', function (): void {
            $this->drop('group_members');
            $this->drop('groups');
        }, reverse: true);
    }
};
