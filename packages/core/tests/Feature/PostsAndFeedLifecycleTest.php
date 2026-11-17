<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Posts\Contracts\FeedService;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\EditPostCommand;
use SocKit\Modules\Posts\Data\GetFeedQuery;
use SocKit\Modules\Posts\Data\PostView;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Posts\Data\TransitionPostStateCommand;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testing\SpyOutboxConsumer;
use SocKit\Testkit\SocKitTestCase;

final class PostsAndFeedLifecycleTest extends SocKitTestCase
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

    public function test_post_lifecycle_idempotency_cas_safety_tombstones_erasure_and_target_slot(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(
            panel: new PanelId('sockit.alpha'),
            kind: new SurfaceKindId('sockit.wall'),
            host: new HostEntityRef('board', '101'),
            incarnation: Incarnation::initial(),
        );

        $posts = $this->app->make(PostService::class);
        $reactions = $this->app->make(ReactionService::class);

        // 1. Publish + idempotent replay + conflict
        $pubKey = new CommandKey('cmd-post-publish-0001');
        $first = $posts->publish(new PublishPostCommand(
            key: $pubKey,
            surface: $surface,
            body: 'Initial post body',
            audience: AudienceSpec::platform(),
        ));
        self::assertTrue($first->isSuccess());
        self::assertNotNull($first->resource);
        $postId = $first->resource->id;

        $replayed = $posts->publish(new PublishPostCommand(
            key: $pubKey,
            surface: $surface,
            body: 'Initial post body',
            audience: AudienceSpec::platform(),
        ));
        self::assertSame($postId, $replayed->resource?->id);

        try {
            $posts->publish(new PublishPostCommand(
                key: $pubKey,
                surface: $surface,
                body: 'Tampered payload',
                audience: AudienceSpec::platform(),
            ));
            self::fail('Expected IdempotencyConflict.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::IdempotencyConflict, $e->problem());
        }

        // 2. Reaction on post works via PostsPlugin's TargetResolver slot!
        $target = new HostEntityRef('post', $postId);
        $reactRes = $reactions->add(new AddReactionCommand(
            key: new CommandKey('cmd-react-post-00001'),
            surface: $surface,
            target: $target,
            reactionType: 'like',
        ));
        self::assertTrue($reactRes->isSuccess());
        self::assertSame(['like' => 1], $reactions->counts($surface, $target)->counts);

        // 3. CAS Edit succeeds on rev 1 -> rev 2; stale rev 1 throws RevisionConflict
        $editRes = $posts->edit(new EditPostCommand(
            key: new CommandKey('cmd-post-edit-00000001'),
            surface: $surface,
            postId: $postId,
            expectedRevision: new Revision(1),
            body: 'Updated body v2',
        ));
        self::assertTrue($editRes->isSuccess());
        self::assertSame(2, $editRes->revision?->value);

        try {
            $posts->edit(new EditPostCommand(
                key: new CommandKey('cmd-post-edit-00000002'),
                surface: $surface,
                postId: $postId,
                expectedRevision: new Revision(1),
                body: 'Stale write',
            ));
            self::fail('Expected RevisionConflict.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::RevisionConflict, $e->problem());
        }

        // 4. Safety hold on draft promotion rejects cleanly with ContentHeld
        $draftRes = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-post-draft-0000001'),
            surface: $surface,
            body: 'Needs review body',
            audience: AudienceSpec::platform(),
            asDraft: true,
        ));
        $draftId = $draftRes->resource?->id ?? '';
        self::assertNotSame('', $draftId);

        $fake->actors->actAsPerson('bob');
        try {
            $posts->get($surface, $draftId);
            self::fail('Draft should be hidden from non-author.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ContentHidden, $e->problem());
        }

        $fake->actors->actAsPerson('alice');
        $fake->safety->holdAll('moderation.flagged');
        $heldPromote = $posts->transition(new TransitionPostStateCommand(
            key: new CommandKey('cmd-post-promote-00001'),
            surface: $surface,
            postId: $draftId,
            expectedRevision: new Revision(1),
            targetState: ContentState::Published,
        ));
        self::assertSame(OperationOutcome::Rejected, $heldPromote->outcome);
        self::assertSame(SockitErrorCode::ContentHeld, $heldPromote->problem);
        $fake->safety->allowAll();

        // 5. Hide -> blocks reader and reaction target; Restore -> re-opens!
        $posts->transition(new TransitionPostStateCommand(
            key: new CommandKey('cmd-post-hide-00000001'),
            surface: $surface,
            postId: $postId,
            expectedRevision: new Revision(2),
            targetState: ContentState::Hidden,
            reasonCode: 'mod.review',
        ));

        $fake->actors->actAsPerson('bob');
        try {
            $posts->get($surface, $postId);
            self::fail('Hidden post should return ContentHidden.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ContentHidden, $e->problem());
        }

        try {
            $reactions->add(new AddReactionCommand(
                key: new CommandKey('cmd-react-hidden-0001'),
                surface: $surface,
                target: $target,
                reactionType: 'love',
            ));
            self::fail('Hidden post target should block new reactions.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ContentHidden, $e->problem());
        }

        $fake->actors->actAsPerson('alice');
        $posts->transition(new TransitionPostStateCommand(
            key: new CommandKey('cmd-post-restore-00001'),
            surface: $surface,
            postId: $postId,
            expectedRevision: new Revision(3),
            targetState: ContentState::Published,
        ));
        self::assertSame('Updated body v2', $posts->get($surface, $postId)->body);

        // 6. Delete -> tombstone recorded, content_deleted thrown; Erase -> nulls bodies across revisions!
        $posts->transition(new TransitionPostStateCommand(
            key: new CommandKey('cmd-post-delete-000001'),
            surface: $surface,
            postId: $postId,
            expectedRevision: new Revision(4),
            targetState: ContentState::Deleted,
        ));
        self::assertDatabaseHas('sk_resource_tombstones', [
            'panel_id' => 'sockit.alpha',
            'resource_kind' => 'post',
            'resource_id' => $postId,
        ]);

        try {
            $posts->get($surface, $postId);
            self::fail('Deleted post should throw ContentDeleted.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ContentDeleted, $e->problem());
        }

        $posts->transition(new TransitionPostStateCommand(
            key: new CommandKey('cmd-post-erase-0000001'),
            surface: $surface,
            postId: $postId,
            expectedRevision: new Revision(5),
            targetState: ContentState::Erased,
        ));

        $rawRow = DB::table('sk_posts')->where('id', $postId)->first();
        self::assertNull($rawRow->current_body);
        self::assertSame(0, DB::table('sk_post_revisions')->where('post_id', $postId)->whereNotNull('body')->count());

        try {
            $posts->get($surface, $postId);
            self::fail('Erased post should throw ContentErased.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ContentErased, $e->problem());
        }
    }

    public function test_feed_keyset_pagination_audience_gate_filtering_and_outbox_replay(): void
    {
        $this->migrateSockitSchema();

        $spy = new SpyOutboxConsumer;
        $this->app->instance(SpyOutboxConsumer::class, $spy);
        $this->app->tag([SpyOutboxConsumer::class], OutboxConsumer::class);

        $fake = $this->installFakeSocKit();
        $alice = new ActorRef('user', 'alice');
        $bob = new ActorRef('user', 'bob');
        $team = new HostEntityRef('team', 'eng');

        $fake->contours->set($alice, 'team', $team, ContourMembership::Live);
        $fake->contours->set($bob, 'team', $team, ContourMembership::Live);
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(
            panel: new PanelId('sockit.alpha'),
            kind: new SurfaceKindId('sockit.wall'),
            host: new HostEntityRef('board', 'feed-1'),
            incarnation: Incarnation::initial(),
        );

        $posts = $this->app->make(PostService::class);
        $feed = $this->app->make(FeedService::class);

        $posts->publish(new PublishPostCommand(new CommandKey('cmd-feed-p1-000000001'), $surface, 'Post 1', audience: AudienceSpec::platform()));
        $posts->publish(new PublishPostCommand(new CommandKey('cmd-feed-p2-000000002'), $surface, 'Post 2 team only', audience: AudienceSpec::contour('team', $team)));
        $posts->publish(new PublishPostCommand(new CommandKey('cmd-feed-p3-000000003'), $surface, 'Post 3', audience: AudienceSpec::platform()));
        $posts->publish(new PublishPostCommand(new CommandKey('cmd-feed-p4-000000004'), $surface, 'Post 4 alice direct', audience: AudienceSpec::direct($alice)));

        // Bob initially sees 3 posts (1, 2, 3) paginated at limit=2; never Post 4 direct!
        $fake->actors->actAsPerson('bob');
        $page1 = $feed->feed(new GetFeedQuery($surface, limit: 2));
        self::assertCount(2, $page1->items);
        self::assertTrue($page1->hasMore);
        self::assertNotNull($page1->nextCursor);

        $page2 = $feed->feed(new GetFeedQuery($surface, limit: 2, cursor: $page1->nextCursor));
        self::assertCount(1, $page2->items);
        self::assertFalse($page2->hasMore);

        // Revoke Bob from team contour -> feed immediately drops Post 2!
        $fake->contours->revoke($bob, 'team', $team);
        $afterRevoke = $feed->feed(new GetFeedQuery($surface, limit: 10));
        $bodies = array_map(static fn (PostView $item): string => (string) $item->body, $afterRevoke->items);
        self::assertSame(['Post 3', 'Post 1'], $bodies);

        // Outbox relay delivers pending events cleanly; transient failure retries without data loss!
        $spy->failNext();
        $relay = $this->app->make(OutboxRelay::class);
        $firstRun = $relay->relayPending(new PanelId('sockit.alpha'), 10, ReplayMode::Normal);
        self::assertSame(3, $firstRun);

        // Second run retries the failed 1st event cleanly
        $secondRun = $relay->relayPending(new PanelId('sockit.alpha'), 10, ReplayMode::Normal);
        self::assertSame(1, $secondRun);
        self::assertCount(4, $spy->recorded());
        self::assertSame(ReplayMode::Normal, $spy->recorded()[0]['mode']);

        // Rebuild reactions console command runs cleanly with zero notifications
        $this->artisan('sockit:reactions:rebuild', ['--panel' => 'sockit.alpha'])
            ->assertExitCode(0);
    }
}
