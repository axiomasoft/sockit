<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use SocKit\Database\SockitMigration;

return new class extends SockitMigration
{
    public function up(): void
    {
        $this->forEachPanelStorage('core', function (): void {
            $surfaces = $this->create('surface_instances', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('panel_id', 96);
                $table->string('kind', 96);
                $table->string('host_entity_type', 96);
                $table->string('host_entity_value', 512);
                $table->unsignedBigInteger('incarnation');
                $table->unsignedBigInteger('revision')->default(1);
                $table->string('lifecycle_state', 32);
                $table->timestampTz('created_at', 6)->useCurrent();
                $table->timestampTz('archived_at', 6)->nullable();
                $table->timestampTz('deleted_at', 6)->nullable();

                $this->unique($table, ['panel_id', 'kind', 'host_entity_type', 'host_entity_value', 'incarnation']);
                $this->index($table, ['panel_id', 'kind', 'lifecycle_state']);
            });
            $this->addCheckIfPostgres($surfaces, 'incarnation_positive', 'incarnation >= 1');
            $this->addCheckIfPostgres($surfaces, 'revision_positive', 'revision >= 1');
            $this->addCheckIfPostgres($surfaces, 'lifecycle_state_valid', "lifecycle_state IN ('active', 'archived', 'deleted')");

            $this->create('command_receipts', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('panel_id', 96);
                $table->string('command_namespace', 384);
                $table->string('command_key', 128);
                $table->string('command_type', 120);
                $table->string('actor_kind', 32);
                $table->string('actor_type', 96)->nullable();
                $table->string('actor_id', 512)->nullable();
                $table->string('on_behalf_of_type', 96)->nullable();
                $table->string('on_behalf_of_id', 512)->nullable();
                $table->string('payload_digest', 72);
                $table->string('status', 24);
                $table->string('resource_kind', 64)->nullable();
                $table->string('resource_id', 64)->nullable();
                $table->unsignedBigInteger('applied_revision')->nullable();
                $table->string('problem_code', 96)->nullable();
                $table->jsonb('result_payload');
                $table->timestampTz('created_at', 6)->useCurrent();
                $table->timestampTz('completed_at', 6)->nullable();

                $this->unique($table, ['panel_id', 'command_namespace', 'command_key']);
            });

            $outbox = $this->create('outbox_events', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('panel_id', 96);
                $table->string('aggregate_type', 64);
                $table->string('aggregate_id', 64);
                $table->unsignedBigInteger('aggregate_revision');
                $table->string('event_type', 160);
                $table->unsignedSmallInteger('event_schema_version');
                $table->string('payload_digest', 72);
                $table->jsonb('payload');
                $table->timestampTz('occurred_at', 6);
                $table->timestampTz('available_at', 6);
                $table->unsignedInteger('attempts')->default(0);
                $table->string('lease_owner', 128)->nullable();
                $table->timestampTz('lease_until', 6)->nullable();
                $table->jsonb('delivered_consumers')->nullable();
                $table->timestampTz('delivered_at', 6)->nullable();
                $table->string('projection_lease_owner', 128)->nullable();
                $table->timestampTz('projection_lease_until', 6)->nullable();
                $table->timestampTz('projection_replayed_at', 6)->nullable();
                $table->string('last_problem_code', 96)->nullable();

                $this->unique($table, ['panel_id', 'aggregate_type', 'aggregate_id', 'aggregate_revision', 'event_type']);
                $this->index($table, ['panel_id', 'delivered_at', 'available_at']);
                $this->index($table, ['panel_id', 'projection_replayed_at', 'available_at']);
            });
            $this->addCheckIfPostgres($outbox, 'attempts_non_negative', 'attempts >= 0');

            $tombstones = $this->create('resource_tombstones', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $table->string('panel_id', 96);
                $table->string('resource_kind', 64);
                $table->string('resource_id', 64);
                $table->unsignedBigInteger('last_revision');
                $table->unsignedBigInteger('incarnation');
                $table->timestampTz('deleted_at', 6);
                $table->timestampTz('erased_at', 6)->nullable();

                $this->unique($table, ['panel_id', 'resource_kind', 'resource_id']);
            });
            $this->addCheckIfPostgres($tombstones, 'last_revision_positive', 'last_revision >= 1');
        });
    }

    public function down(): void
    {
        $this->forEachPanelStorage('core', function (): void {
            $this->drop('resource_tombstones');
            $this->drop('outbox_events');
            $this->drop('command_receipts');
            $this->drop('surface_instances');
        }, reverse: true);
    }
};
