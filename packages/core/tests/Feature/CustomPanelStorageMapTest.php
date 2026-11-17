<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Core\Tests\Fixtures\CustomMapWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelCustomMapProvider;
use SocKit\Diagnostics\SockitDoctorCheck;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testkit\SocKitTestCase;

final class CustomPanelStorageMapTest extends SocKitTestCase
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

    public function test_custom_prefix_and_per_panel_table_map_migrate_and_operate_end_to_end(): void
    {
        $this->migrateSockitSchema();

        self::assertTrue(Schema::hasTable('wall_posts'));
        self::assertTrue(Schema::hasTable('club_groups'));
        self::assertTrue(Schema::hasTable('cm_surface_instances'));
        self::assertTrue(Schema::hasTable('cm_reactions'));
        self::assertFalse(Schema::hasTable('sk_posts'));
        self::assertFalse(Schema::hasTable('cm_posts'));

        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(
            panel: new PanelId('sockit.custom-map'),
            kind: new SurfaceKindId('sockit.wall'),
            host: new HostEntityRef('board', 'map-1'),
            incarnation: Incarnation::initial(),
        );

        $posts = $this->app->make(PostService::class);
        $groups = $this->app->make(GroupService::class);
        $reactions = $this->app->make(ReactionService::class);

        $postResult = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-map-post-0001'),
            surface: $surface,
            body: 'Stored in mapped wall_posts',
            audience: AudienceSpec::platform(),
        ));
        self::assertTrue($postResult->isSuccess());
        $postId = (string) $postResult->resource?->id;

        $groupResult = $groups->create(new CreateGroupCommand(
            key: new CommandKey('cmd-map-group-0001'),
            surface: $surface,
            slug: 'custom-club',
            title: 'Custom Club',
            visibility: GroupVisibility::Public,
        ));
        self::assertTrue($groupResult->isSuccess());

        $reactionResult = $reactions->add(new AddReactionCommand(
            key: new CommandKey('cmd-map-react-0001'),
            surface: $surface,
            target: new HostEntityRef('post', $postId),
            reactionType: 'like',
        ));
        self::assertTrue($reactionResult->isSuccess());

        self::assertSame(1, DB::table('wall_posts')->count());
        self::assertSame(1, DB::table('club_groups')->count());
        self::assertSame(1, DB::table('cm_reactions')->count());

        $checks = $this->app->make(SockitDoctorCheck::class)->run(new DoctorContext(probeStorage: true));
        self::assertCount(1, $checks);
        self::assertSame(CheckStatus::Ok, $checks[0]->status);
        self::assertSame('sockit.doctor.healthy', $checks[0]->code);
    }
}
