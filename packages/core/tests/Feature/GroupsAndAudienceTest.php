<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\GroupRef;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Data\AddGroupMemberCommand;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Data\RemoveGroupMemberCommand;
use SocKit\Modules\Groups\Enums\GroupMemberRole;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Modules\Posts\Contracts\FeedService;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\GetFeedQuery;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testkit\SocKitTestCase;

final class GroupsAndAudienceTest extends SocKitTestCase
{
    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [PanelAlphaProvider::class];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [AlphaWallSurfaceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function sockitPrivacyFloor(): array
    {
        return [
            'allowed_scopes' => ['direct', 'group', 'contour', 'platform'],
            'default_scope' => 'platform',
            'max_scope' => 'platform',
            'minor_default_scope' => 'direct',
            'minor_max_scope' => 'group',
            'contour_levels' => ['team'],
        ];
    }

    public function test_groups_membership_controls_live_group_scoped_post_reads_and_feed(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');
        $fake->consent->allowAll();

        $surface = new SurfaceRef(
            panel: new PanelId('sockit.alpha'),
            kind: new SurfaceKindId('sockit.wall'),
            host: new HostEntityRef('board', 'grp-wall'),
            incarnation: Incarnation::initial(),
        );

        $groups = $this->app->make(GroupService::class);
        $posts = $this->app->make(PostService::class);
        $feed = $this->app->make(FeedService::class);

        $createRes = $groups->create(new CreateGroupCommand(
            key: new CommandKey('cmd-grp-create-00001'),
            surface: $surface,
            slug: 'engineers',
            title: 'Engineering Group',
            visibility: GroupVisibility::Private,
        ));
        self::assertTrue($createRes->isSuccess());
        $groupId = $createRes->resource?->id ?? '';
        self::assertNotSame('', $groupId);

        // Creator Alice is not auto-enrolled on create() (P5.2); enroll Alice explicitly with consent
        $groups->addMember(new AddGroupMemberCommand(
            key: new CommandKey('cmd-grp-add-alice-001'),
            surface: $surface,
            groupId: $groupId,
            expectedRevision: new Revision(1),
            member: new ActorRef('user', 'alice'),
            role: GroupMemberRole::Owner,
        ));

        // Alice publishes group-scoped post and can read it immediately
        $postRes = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-grp-post-0000001'),
            surface: $surface,
            body: 'Internal group update',
            audience: AudienceSpec::group(new GroupRef($groupId)),
        ));
        $postId = $postRes->resource?->id ?? '';
        self::assertSame('Internal group update', $posts->get($surface, $postId)->body);

        // Non-member Bob is denied read and sees empty feed
        $fake->actors->actAsPerson('bob');
        try {
            $posts->get($surface, $postId);
            self::fail('Non-member Bob must not read group post.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }
        self::assertCount(0, $feed->feed(new GetFeedQuery($surface))->items);

        // Alice enrolls Bob -> Bob immediately reads group post & feed!
        $fake->actors->actAsPerson('alice');
        $groups->addMember(new AddGroupMemberCommand(
            key: new CommandKey('cmd-grp-add-bob-0001'),
            surface: $surface,
            groupId: $groupId,
            expectedRevision: new Revision(2),
            member: new ActorRef('user', 'bob'),
        ));

        $fake->actors->actAsPerson('bob');
        self::assertSame('Internal group update', $posts->get($surface, $postId)->body);
        self::assertCount(1, $feed->feed(new GetFeedQuery($surface))->items);

        // Alice removes Bob -> Bob immediately loses read access!
        $fake->actors->actAsPerson('alice');
        $groups->removeMember(new RemoveGroupMemberCommand(
            key: new CommandKey('cmd-grp-rem-bob-0001'),
            surface: $surface,
            groupId: $groupId,
            expectedRevision: new Revision(3),
            member: new ActorRef('user', 'bob'),
        ));

        $fake->actors->actAsPerson('bob');
        try {
            $posts->get($surface, $postId);
            self::fail('Removed member Bob must lose read access immediately.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }
        self::assertCount(0, $feed->feed(new GetFeedQuery($surface))->items);
    }
}
