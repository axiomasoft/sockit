<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SocKit\Configuration\SockitOptions;
use SocKit\Core\Tests\Fixtures\CustomMapWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelCustomMapProvider;
use SocKit\Database\Internal\SockitTables;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Internal\SockitPanelRegistry;
use SocKit\Testkit\SocKitTestCase;

final class MigrationSnapshotRollbackTest extends SocKitTestCase
{
    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [PanelCustomMapProvider::class];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [CustomMapWallSurfaceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function sockitPrivacyFloor(): array
    {
        return [
            'allowed_scopes' => ['direct', 'group', 'contour', 'platform'],
            'default_scope' => 'platform',
            'max_scope' => 'platform',
            'minor_default_scope' => 'direct',
            'minor_max_scope' => 'platform',
            'contour_levels' => ['team'],
        ];
    }

    public function test_migration_rollback_drops_exact_snapshotted_tables_even_after_prefix_and_panel_changes(): void
    {
        $this->migrateSockitSchema();
        self::assertTrue(Schema::hasTable('cm_surface_instances'));
        self::assertTrue(Schema::hasTable('cm_reactions'));
        self::assertTrue(Schema::hasTable('wall_posts'));
        self::assertTrue(Schema::hasTable('club_groups'));
        self::assertTrue(Schema::hasTable('sockit_schema_snapshots'));

        // Decoy v2_posts table plus mutated runtime prefix ('v2_') and removed panels BEFORE rollback!
        Schema::create('v2_posts', static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
        });
        $this->app->make(ConfigRepository::class)->set('sockit.storage.table_prefix', 'v2_');
        $this->app->forgetInstance(SockitOptions::class);
        $this->app->forgetInstance(SockitTables::class);
        $this->app->instance(PanelDefinitions::class, new SockitPanelRegistry);

        $migrator = $this->app->make('migrator');
        $migrator->rollback([
            dirname(__DIR__, 2).'/database/migrations/core',
            dirname(__DIR__, 2).'/database/migrations/reactions',
            dirname(__DIR__, 2).'/database/migrations/posts',
            dirname(__DIR__, 2).'/database/migrations/groups',
        ]);

        self::assertFalse(Schema::hasTable('cm_surface_instances'));
        self::assertFalse(Schema::hasTable('cm_reactions'));
        self::assertFalse(Schema::hasTable('wall_posts'));
        self::assertFalse(Schema::hasTable('club_groups'));
        self::assertFalse(Schema::hasTable('sockit_schema_snapshots'));
        self::assertTrue(Schema::hasTable('v2_posts'), 'Decoy table must never be touched by rollback.');
    }
}
