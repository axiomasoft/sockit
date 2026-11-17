<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Override;
use Packman\Context\Data\ScopeRef;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\BetaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Core\Tests\Fixtures\PanelBetaProvider;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Data\AddGroupMemberCommand;
use SocKit\Modules\Groups\Data\ApproveGroupMemberCommand;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Data\InviteGroupMemberCommand;
use SocKit\Modules\Groups\Data\JoinGroupCommand;
use SocKit\Modules\Groups\Data\LeaveGroupCommand;
use SocKit\Modules\Groups\Data\RemoveGroupMemberCommand;
use SocKit\Modules\Groups\Data\SetGroupJoinPolicyCommand;
use SocKit\Modules\Groups\Enums\GroupJoinPolicy;
use SocKit\Modules\Groups\Enums\GroupMemberRole;
use SocKit\Modules\Groups\Enums\GroupMemberStatus;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Modules\Posts\Contracts\FeedService;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\EditPostCommand;
use SocKit\Modules\Posts\Data\FeedCursor;
use SocKit\Modules\Posts\Data\GetFeedQuery;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OperationResult;
use SocKit\Operations\Data\OutboxEventRecord;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\SockitPanelProvider;
use SocKit\Registrars\Internal\SockitRuntime;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Exceptions\SurfaceException;
use SocKit\Testing\Fakes\Actors\FakeActorContextResolver;
use SocKit\Testing\Fakes\Operations\SpyOutboxConsumer;
use SocKit\Testkit\SocKitTestCase;

final class ReAuditHardeningTest extends SocKitTestCase
{
    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [PanelAlphaProvider::class, PanelBetaProvider::class];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [AlphaWallSurfaceProvider::class, BetaWallSurfaceProvider::class];
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

    public function test_cross_surface_group_mutation_is_denied_under_lock(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');
        $fake->consent->allowAll();

        $surfaceA = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'grp-surf-a'), Incarnation::initial());
        $surfaceB = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'grp-surf-b'), Incarnation::initial());

        $groups = $this->app->make(GroupService::class);
        $created = $groups->create(new CreateGroupCommand(
            key: new CommandKey('cmd-xsurf-grp-create-1'),
            surface: $surfaceA,
            slug: 'cross-surface-grp',
            title: 'Bound Surface Group',
        ));
        $groupId = (string) $created->resource?->id;

        // Cross-surface addMember via accessible Surface B MUST fail closed!
        try {
            $groups->addMember(new AddGroupMemberCommand(
                key: new CommandKey('cmd-xsurf-grp-add-1'),
                surface: $surfaceB,
                groupId: $groupId,
                expectedRevision: new Revision(1),
                member: new ActorRef('user', 'bob'),
            ));
            self::fail('Cross-surface addMember must be rejected.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        // Cross-surface removeMember via Surface B MUST fail closed!
        try {
            $groups->removeMember(new RemoveGroupMemberCommand(
                key: new CommandKey('cmd-xsurf-grp-rem-1'),
                surface: $surfaceB,
                groupId: $groupId,
                expectedRevision: new Revision(1),
                member: new ActorRef('user', 'bob'),
            ));
            self::fail('Cross-surface removeMember must be rejected.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        self::assertSame(0, DB::table('sk_group_members')->count());
    }

    public function test_cloned_application_container_never_leaks_boot_container_actor(): void
    {
        $this->migrateSockitSchema();
        $fakeAlice = $this->installFakeSocKit();
        $fakeAlice->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'clone-iso'), Incarnation::initial());

        // Pre-resolve services & panel provider on original $app BEFORE cloning
        $heldPosts = $this->app->make(PostService::class);
        $heldPanelProvider = $this->app->make(SockitPanelProvider::class);

        $alicePub = $heldPosts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-clone-alice-pub-1'),
            surface: $surface,
            body: 'From Alice',
            audience: AudienceSpec::platform(),
        ));
        self::assertTrue($alicePub->isSuccess());

        // Clone $app WITHOUT changing global Container::setInstance first -> fresh resolution from $clone1 resolves Bob!
        $clone1 = clone $this->app;
        $bobResolver1 = (new FakeActorContextResolver)->actAsPerson('bob');
        $clone1->instance(ActorContextResolver::class, $bobResolver1);

        $clonePosts = $clone1->make(PostService::class);
        $clonePub = $clonePosts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-clone-bob-pub-1'),
            surface: $surface,
            body: 'From Bob clone1',
            audience: AudienceSpec::platform(),
        ));
        $clone1Post = $clonePosts->get($surface, (string) $clonePub->resource?->id);
        self::assertSame('user:bob', $clone1Post->author->toWire());

        // Octane-style clone WITH Container::setInstance($clone2): even ALREADY-HELD $heldPosts & $heldPanelProvider switch to Bob!
        $clone2 = clone $this->app;
        $bobResolver2 = (new FakeActorContextResolver)->actAsPerson('carol');
        $clone2->instance(ActorContextResolver::class, $bobResolver2);
        Container::setInstance($clone2);

        try {
            $carolPub = $heldPosts->publish(new PublishPostCommand(
                key: new CommandKey('cmd-clone-carol-pub-1'),
                surface: $surface,
                body: 'From Carol via held service',
                audience: AudienceSpec::platform(),
            ));
            $carolPost = $heldPosts->get($surface, (string) $carolPub->resource?->id);
            self::assertSame('user:carol', $carolPost->author->toWire());

            $receiptRow = DB::table('sk_command_receipts')->where('command_key', 'cmd-clone-carol-pub-1')->first();
            self::assertSame('carol', (string) $receiptRow->actor_id);

            $panelAccess = $heldPanelProvider->access(
                new PanelContext(new ScopeRef('default'), 'user', 'carol'),
                new PanelReference('sockit', 'alpha', null),
            );
            self::assertTrue($panelAccess->view);
        } finally {
            Container::setInstance($this->app);
        }
    }

    public function test_receipt_replay_rejects_foreign_actor_and_narrowed_post_audience(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');
        $fake->consent->allowAll();

        $surface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'replay-own'), Incarnation::initial());
        $groups = $this->app->make(GroupService::class);
        $posts = $this->app->make(PostService::class);

        $grpRes = $groups->create(new CreateGroupCommand(new CommandKey('cmd-rcpt-grp-create-1'), $surface, 'replay-grp', 'Replay Group'));
        $groupId = (string) $grpRes->resource?->id;
        $groups->addMember(new AddGroupMemberCommand(
            key: new CommandKey('cmd-rcpt-grp-add-alice'),
            surface: $surface,
            groupId: $groupId,
            expectedRevision: new Revision(1),
            member: new ActorRef('user', 'alice'),
        ));

        // 1) Platform post command replay by DIFFERENT actor (Bob) MUST fail closed at receipt actor ownership check!
        $platformKey = new CommandKey('cmd-rcpt-alice-plat-1');
        $platformCmd = new PublishPostCommand($platformKey, $surface, 'Platform announcement', audience: AudienceSpec::platform());
        self::assertTrue($posts->publish($platformCmd)->isSuccess());

        $fake->actors->actAsPerson('bob');
        try {
            $posts->publish($platformCmd);
            self::fail('Foreign actor Bob must not replay Alice command receipt.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame('receipt.actor_mismatch', $e->detail()['reason'] ?? null);
        }

        // 2) Alice publishes Group post, then audience narrows to Direct(carol) -> Alice replaying publish() MUST fail closed!
        $fake->actors->actAsPerson('alice');
        $aliceKey = new CommandKey('cmd-rcpt-alice-pub-1');
        $pubCmd = new PublishPostCommand($aliceKey, $surface, 'Group secret', audience: AudienceSpec::group(new GroupRef($groupId)));
        $res = $posts->publish($pubCmd);
        self::assertTrue($res->isSuccess());
        $postId = (string) $res->resource?->id;

        $posts->edit(new EditPostCommand(
            key: new CommandKey('cmd-rcpt-narrow-edit-1'),
            surface: $surface,
            postId: $postId,
            expectedRevision: new Revision(1),
            body: 'Narrowed away',
            audience: AudienceSpec::direct(new ActorRef('user', 'carol')),
        ));

        try {
            $posts->publish($pubCmd);
            self::fail('Publish replay must re-verify stored current audience.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }
    }

    public function test_reaction_rejects_unfenced_target_resolver_and_mid_flight_group_revocation_under_lock(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit(forceFakeGroups: true);
        $fake->actors->actAsPerson('bob');

        $surface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'react-fence'), Incarnation::initial());
        $hostTarget = new HostEntityRef('article', 'unfenced-1');
        $reactions = $this->app->make(ReactionService::class);

        // 1) Plain non-TargetWriteFence TargetResolver MUST fail closed on writes!
        $plainResolver = new readonly class($hostTarget) implements TargetResolver
        {
            public function __construct(private HostEntityRef $target) {}

            #[Override]
            public function supports(HostEntityRef $target): bool
            {
                return $target->equals($this->target);
            }

            #[Override]
            public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
            {
                return TargetResolution::available($target, new ActorRef('user', 'alice'), AudienceSpec::platform(), new Revision(1));
            }
        };
        $this->app->instance(TargetResolver::class, $plainResolver);

        try {
            $reactions->add(new AddReactionCommand(new CommandKey('cmd-react-unfenced-1'), $surface, $hostTarget, 'like'));
            self::fail('Unfenced TargetResolver must be rejected for reactions.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame(SockitErrorCode::AuthorizationUnknown, $e->problem());
        }

        // 2) FakeTargetResolver without withLocalWriteFence() OR null privacyRevision ALSO fails closed!
        $this->app->instance(TargetResolver::class, $fake->targets);
        $fake->targets->register($hostTarget, new ActorRef('user', 'alice'), AudienceSpec::platform(), new Revision(1));

        try {
            $reactions->add(new AddReactionCommand(new CommandKey('cmd-react-fake-unfenced-1'), $surface, $hostTarget, 'like'));
            self::fail('FakeTargetResolver without local write fence must fail closed.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame(SockitErrorCode::AuthorizationUnknown, $e->problem());
        }

        // 3) Mid-flight group membership revocation right as reaction TX locks group -> denied deterministically!
        $bob = new ActorRef('user', 'bob');
        $fake->groups->addForPanel($bob, $surface->panel, 'grp-midflight');
        $groupTarget = new HostEntityRef('article', 'grp-locked-1');
        $fake->targets->withLocalWriteFence()->register(
            $groupTarget,
            new ActorRef('user', 'alice'),
            AudienceSpec::group(new GroupRef('grp-midflight')),
            new Revision(1),
        );

        $fake->groups->onLock(static function (PanelId $panel, GroupRef $group) use ($fake, $bob): void {
            $fake->groups->remove($bob, $group);
        });

        try {
            $reactions->add(new AddReactionCommand(new CommandKey('cmd-react-midrevoke-1'), $surface, $groupTarget, 'like'));
            self::fail('Mid-flight group revocation under lock must reject reaction.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        self::assertSame(0, DB::table('sk_reactions')->count());
    }

    public function test_denying_group_management_surface_blocks_group_audience_post_reads_on_other_surfaces(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->consent->allowAll();
        $fake->actors->actAsPerson('alice');

        $mgmtSurface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'mgmt-board'), Incarnation::initial());
        $otherSurface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'other-wall'), Incarnation::initial());

        $groups = $this->app->make(GroupService::class);
        $posts = $this->app->make(PostService::class);
        $feed = $this->app->make(FeedService::class);

        $grpRes = $groups->create(new CreateGroupCommand(new CommandKey('cmd-mgmtsurf-grp-1'), $mgmtSurface, 'mgmt-grp', 'Mgmt Group'));
        $groupId = (string) $grpRes->resource?->id;

        $alice = new ActorRef('user', 'alice');
        $bob = new ActorRef('user', 'bob');
        $groups->addMember(new AddGroupMemberCommand(new CommandKey('cmd-mgmtsurf-add-alice'), $mgmtSurface, $groupId, new Revision(1), $alice, GroupMemberRole::Owner));
        $groups->addMember(new AddGroupMemberCommand(new CommandKey('cmd-mgmtsurf-add-bob'), $mgmtSurface, $groupId, new Revision(2), $bob));

        $postRes = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-mgmtsurf-post-1'),
            surface: $otherSurface,
            body: 'Visible across surfaces while mgmt admitted',
            audience: AudienceSpec::group(new GroupRef($groupId)),
        ));
        $postId = (string) $postRes->resource?->id;

        // Bob reads post on otherSurface cleanly while mgmtSurface is active & admitted
        $fake->actors->actAsPerson('bob');
        self::assertSame('Visible across surfaces while mgmt admitted', $posts->get($otherSurface, $postId)->body);

        // Deny admission ONLY on mgmtSurface (otherSurface stays Allowed!) -> Bob immediately denied on otherSurface!
        $fake->surfacePolicy->admitUsing(static function (ActorContext $viewer, SurfaceRef $surface) use ($mgmtSurface): AudienceVerdict {
            if ($surface->host->equals($mgmtSurface->host)) {
                return AudienceVerdict::deny('mgmt_surface.denied');
            }

            return AudienceVerdict::allow('other_surface.admitted');
        });

        try {
            $posts->get($otherSurface, $postId);
            self::fail('Denying management surface must block group post reads on other surfaces.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }
        self::assertCount(0, $feed->feed(new GetFeedQuery($otherSurface))->items);

        // Archive mgmtSurface (archiveReaders=false) -> even with admit restored, archived mgmtSurface denies reads!
        $fake->surfacePolicy->allowAll();
        $fake->actors->actAsPerson('alice');
        $mgmtInstance = $this->app->make(SurfaceService::class)->get($mgmtSurface);
        $this->app->make(SurfaceService::class)->archive(new CommandKey('cmd-mgmtsurf-arch-1'), $mgmtSurface, $mgmtInstance->revision);

        $fake->actors->actAsPerson('bob');
        try {
            $posts->get($otherSurface, $postId);
            self::fail('Archiving management surface without archiveReaders must block group post reads.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }
    }

    public function test_feed_re_verifies_post_state_after_audience_evaluation_before_exposing_body(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'feed-hide-race'), Incarnation::initial());
        $posts = $this->app->make(PostService::class);

        $pub = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-feedrace-pub-1'),
            surface: $surface,
            body: 'SECRET_BODY_MUST_NOT_LEAK',
            audience: AudienceSpec::platform(),
        ));
        $postId = (string) $pub->resource?->id;

        // Swap AudienceResolver to simulate post being hidden right after readMany evaluates its audience!
        $innerResolver = $this->app->make(AudienceResolver::class);
        $racyResolver = new readonly class($innerResolver, $postId) implements AudienceResolver
        {
            public function __construct(
                private AudienceResolver $inner,
                private string $postId,
            ) {}

            #[Override]
            public function decideRead(ActorContext $viewer, AudienceSubject $subject): AudienceVerdict
            {
                $verdict = $this->inner->decideRead($viewer, $subject);

                // Hide the post concurrently right after AudienceResolver approved it
                DB::table('sk_posts')->where('id', $this->postId)->update([
                    'state' => ContentState::Hidden->value,
                    'hidden_at' => gmdate('Y-m-d H:i:s.uP'),
                    'revision' => 2,
                ]);

                return $verdict;
            }

            #[Override]
            public function decidePublish(ActorContext $author, AudienceSubject $subject, PrivacyFloor $floor): AudienceVerdict
            {
                return $this->inner->decidePublish($author, $subject, $floor);
            }
        };
        $this->app->instance(AudienceResolver::class, $racyResolver);

        $page = $this->app->make(FeedService::class)->feed(new GetFeedQuery($surface));
        self::assertCount(0, $page->items);
    }

    public function test_outbox_never_marks_delivered_without_supporting_consumer_and_tracks_partial_multi_consumer_progress(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'outbox-zero'), Incarnation::initial());
        $this->app->make(PostService::class)->publish(new PublishPostCommand(
            key: new CommandKey('cmd-outbox-zero-1'),
            surface: $surface,
            body: 'Outbox event test',
            audience: AudienceSpec::platform(),
        ));

        $relay = $this->app->make(OutboxRelay::class);

        // 0 consumers registered -> MUST NOT mark delivered_at!
        self::assertSame(0, $relay->relayPending($surface->panel, 50, ReplayMode::Normal));
        $postEvent = DB::table('sk_outbox_events')->where('event_type', 'sockit.posts.published')->first();
        self::assertNull($postEvent->delivered_at);
        self::assertSame('outbox.no_supporting_consumer', (string) $postEvent->last_problem_code);

        // Unrelated non-supporting consumer -> STILL MUST NOT mark delivered_at!
        $nonSupporting = new class implements OutboxConsumer
        {
            #[Override]
            public function consumerKey(): string
            {
                return 'unrelated.consumer';
            }

            #[Override]
            public function supports(string $eventType): bool
            {
                return false;
            }

            #[Override]
            public function consume(OutboxEventRecord $event, ReplayMode $mode): void {}
        };
        $this->app->instance('test.outbox.nonsupport', $nonSupporting);
        $this->app->tag(['test.outbox.nonsupport'], OutboxConsumer::class);
        self::assertSame(0, $relay->relayPending($surface->panel, 50, ReplayMode::Normal));

        // Multi-consumer partial failure: consumerA succeeds, consumerB fails -> retry skips consumerA and completes consumerB!
        $consumerA = new SpyOutboxConsumer('consumer.a');
        $consumerB = new SpyOutboxConsumer('consumer.b')->failNext();
        $this->app->instance('test.outbox.a', $consumerA);
        $this->app->instance('test.outbox.b', $consumerB);
        $this->app->tag(['test.outbox.a', 'test.outbox.b'], OutboxConsumer::class);

        self::assertSame(0, $relay->relayPending($surface->panel, 50, ReplayMode::Normal));
        $afterPartial = DB::table('sk_outbox_events')->where('event_type', 'sockit.posts.published')->first();
        self::assertNull($afterPartial->delivered_at);

        // Retry: consumerA must NOT be re-invoked for already-ACKed event; consumerB now succeeds and completes delivery!
        $delivered = $relay->relayPending($surface->panel, 50, ReplayMode::Normal);
        self::assertGreaterThanOrEqual(1, $delivered);
        $postEventsForA = array_filter($consumerA->recorded(), static fn (array $e): bool => $e['event']->eventType === 'sockit.posts.published');
        $postEventsForB = array_filter($consumerB->recorded(), static fn (array $e): bool => $e['event']->eventType === 'sockit.posts.published');
        self::assertCount(1, $postEventsForA);
        self::assertCount(1, $postEventsForB);
        self::assertNotNull(DB::table('sk_outbox_events')->where('event_type', 'sockit.posts.published')->first()->delivered_at);
    }

    public function test_envelope_refuses_nested_transactions_and_enforces_mandatory_authorizer_and_admission(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'env-guards'), Incarnation::initial());
        $posts = $this->app->make(PostService::class);
        $envelopeRunner = $this->app->make(OperationEnvelopeRunner::class);

        // #9: Active outer transaction MUST fail closed with ConfigurationInvalid (nestedTransaction)!
        try {
            DB::transaction(static function () use ($posts, $surface): void {
                $posts->publish(new PublishPostCommand(
                    key: new CommandKey('cmd-nested-tx-1000'),
                    surface: $surface,
                    body: 'Must never run inside outer TX',
                    audience: AudienceSpec::platform(),
                ));
            });
            self::fail('Expected nested transaction rejection.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ConfigurationInvalid, $e->problem());
        }

        // #10: OperationEnvelopeRunner::run() enforces ActionAuthorizer & admitForWrite even with empty $preTransactionChecks!
        $namespace = new CommandNamespace($fake->actors->current()->installation, $surface->panel, 'test', 'direct.envelope');
        $cmdEnvelope = new CommandEnvelope(
            key: new CommandKey('cmd-direct-env-0001'),
            namespace: $namespace,
            digest: PayloadDigest::of('direct.envelope', ['v' => 1]),
        );

        $fake->authorizer->deny('sockit.custom.op', 'envelope.authorizer_denied');
        try {
            $envelopeRunner->run(
                envelope: $cmdEnvelope,
                surface: $surface,
                action: 'sockit.custom.op',
                preTransactionChecks: static function (ActorContext $actor): void {},
                mutation: static fn (ActorContext $actor, Connection $conn): array => [
                    'result' => OperationResult::confirmed('x', new ResourceRef($surface->panel, ResourceKind::SurfaceInstance, 'x'), new Revision(1)),
                ],
            );
            self::fail('Envelope must enforce ActionAuthorizer unconditionally.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame(SockitErrorCode::AuthorizationDenied, $e->problem());
        }

        $fake->authorizer->reset();
        $fake->surfacePolicy->readOnlyAdmission('envelope.read_only');
        try {
            $envelopeRunner->run(
                envelope: $cmdEnvelope,
                surface: $surface,
                action: 'sockit.custom.op',
                preTransactionChecks: static function (ActorContext $actor): void {},
                mutation: static fn (ActorContext $actor, Connection $conn): array => [
                    'result' => OperationResult::confirmed('x', new ResourceRef($surface->panel, ResourceKind::SurfaceInstance, 'x'), new Revision(1)),
                ],
            );
            self::fail('Envelope must reject ReadOnly surface admission unconditionally.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }
    }

    public function test_groups_enforce_no_auto_creator_enrollment_join_policies_and_membership_consent(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'p53-groups'), Incarnation::initial());
        $groups = $this->app->make(GroupService::class);
        $alice = new ActorRef('user', 'alice');
        $bob = new ActorRef('user', 'bob');
        $carol = new ActorRef('user', 'carol');

        // Create Approval-policy group -> creator Alice MUST NOT be auto-enrolled!
        $grpRes = $groups->create(new CreateGroupCommand(
            key: new CommandKey('cmd-p53-create-0001'),
            surface: $surface,
            slug: 'p53-club',
            title: 'P5.3 Club',
            visibility: GroupVisibility::Private,
            joinPolicy: GroupJoinPolicy::Approval,
        ));
        $groupId = (string) $grpRes->resource?->id;
        self::assertSame(0, DB::table('sk_group_members')->where('group_id', $groupId)->count());

        // Bob requests join under Approval -> creates Pending row (NOT Active!), consent not consumed yet
        $fake->actors->actAsPerson('bob');
        $groups->join(new JoinGroupCommand(new CommandKey('cmd-p53-bob-req-1'), $surface, $groupId, new Revision(1)));
        $bobRow = DB::table('sk_group_members')->where('group_id', $groupId)->where('actor_id', 'bob')->first();
        self::assertSame(GroupMemberStatus::Pending->value, (string) $bobRow->status);

        // Alice approves Bob WITHOUT Bob consent -> denied (stays Pending)!
        $fake->actors->actAsPerson('alice');
        try {
            $groups->approve(new ApproveGroupMemberCommand(new CommandKey('cmd-p53-app-noconsent'), $surface, $groupId, new Revision(2), $bob));
            self::fail('Approve without MembershipConsent must fail closed.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame('consent.required', $e->detail()['reason'] ?? null);
        }

        // Grant Bob consent -> approve succeeds with generation recorded!
        $fake->consent->allow($bob, $groupId, 'consent-bob-1');
        $appRes = $groups->approve(new ApproveGroupMemberCommand(new CommandKey('cmd-p53-app-ok-1'), $surface, $groupId, new Revision(2), $bob, GroupMemberRole::Moderator));
        self::assertTrue($appRes->isSuccess());
        $bobActive = DB::table('sk_group_members')->where('group_id', $groupId)->where('actor_id', 'bob')->first();
        self::assertSame(GroupMemberStatus::Active->value, (string) $bobActive->status);
        self::assertSame('consent-bob-1', (string) $bobActive->consent_generation);

        $fake->actors->actAsPerson('bob');
        $view = $groups->get($surface->panel, $groupId);
        self::assertSame(GroupJoinPolicy::Approval, $view->joinPolicy);
        self::assertSame(2, $view->membershipGeneration);

        // Switch policy to Invite -> Invite Carol -> uninvited Alice denied -> Carol recheck failure blocks activation -> Carol join succeeds!
        $fake->actors->actAsPerson('alice');
        $groups->setJoinPolicy(new SetGroupJoinPolicyCommand(new CommandKey('cmd-p53-policy-invite'), $surface, $groupId, new Revision(3), GroupJoinPolicy::Invite));
        $groups->invite(new InviteGroupMemberCommand(new CommandKey('cmd-p53-inv-carol'), $surface, $groupId, new Revision(4), $carol, GroupMemberRole::Member));

        $fake->actors->actAsPerson('alice');
        try {
            $groups->join(new JoinGroupCommand(new CommandKey('cmd-p53-uninv-alice'), $surface, $groupId, new Revision(5)));
            self::fail('Uninvited user must not join under Invite policy.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame('groups.invite_required', $e->detail()['reason'] ?? null);
        }

        $fake->actors->actAsPerson('carol');
        $fake->consent->allow($carol, $groupId, 'consent-carol-1')->failRecheck($carol, $groupId);
        try {
            $groups->join(new JoinGroupCommand(new CommandKey('cmd-p53-carol-revoke'), $surface, $groupId, new Revision(5)));
            self::fail('Revoked consent under lock must block activation.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame('consent.revoked', $e->detail()['reason'] ?? null);
        }

        $fake->consent->reset()->allow($carol, $groupId, 'consent-carol-2');
        $groups->join(new JoinGroupCommand(new CommandKey('cmd-p53-carol-join-ok'), $surface, $groupId, new Revision(5)));

        // Switch policy to Auto -> Alice joins directly with consent; leave() works EVEN WHEN consent denies!
        $groups->setJoinPolicy(new SetGroupJoinPolicyCommand(new CommandKey('cmd-p53-policy-auto'), $surface, $groupId, new Revision(6), GroupJoinPolicy::Auto));
        $fake->consent->allow($alice, $groupId, 'consent-alice-1');
        $fake->actors->actAsPerson('alice');
        $groups->join(new JoinGroupCommand(new CommandKey('cmd-p53-alice-auto'), $surface, $groupId, new Revision(7)));

        $fake->consent->deny($alice, $groupId, 'consent.cannot_veto_leave');
        $leaveRes = $groups->leave(new LeaveGroupCommand(new CommandKey('cmd-p53-alice-leave'), $surface, $groupId, new Revision(8)));
        self::assertTrue($leaveRes->isSuccess());
        self::assertSame(GroupMemberStatus::Left->value, (string) DB::table('sk_group_members')->where('group_id', $groupId)->where('actor_id', 'alice')->first()->status);

        // Closed policy denies join/invite/addMember!
        $groups->setJoinPolicy(new SetGroupJoinPolicyCommand(new CommandKey('cmd-p53-policy-closed'), $surface, $groupId, new Revision(9), GroupJoinPolicy::Closed));
        try {
            $groups->join(new JoinGroupCommand(new CommandKey('cmd-p53-closed-join'), $surface, $groupId, new Revision(10)));
            self::fail('Closed policy must deny join.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame('groups.join_policy_closed', $e->detail()['reason'] ?? null);
        }
    }

    public function test_feed_cursor_rejects_unsigned_tampered_cross_context_cross_viewer_and_expired_tokens(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'cursor-sec'), Incarnation::initial());
        $otherSurface = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'cursor-other'), Incarnation::initial());

        $posts = $this->app->make(PostService::class);
        $feed = $this->app->make(FeedService::class);

        $p1 = $posts->publish(new PublishPostCommand(new CommandKey('cmd-cur-p1-00001'), $surface, 'First', audience: AudienceSpec::platform()));
        $posts->publish(new PublishPostCommand(new CommandKey('cmd-cur-p2-00001'), $surface, 'Second', audience: AudienceSpec::platform()));
        $posts->publish(new PublishPostCommand(new CommandKey('cmd-cur-p3-00001'), $surface, 'Third', audience: AudienceSpec::platform()));

        $page1 = $feed->feed(new GetFeedQuery($surface, limit: 1));
        self::assertNotNull($page1->nextCursor);

        // 1) Unsigned raw FeedCursor rejected
        try {
            $feed->feed(new GetFeedQuery($surface, limit: 1, cursor: new FeedCursor(new DateTimeImmutable('now', new DateTimeZone('UTC')), (string) $p1->resource?->id)));
            self::fail('Unsigned cursor must be rejected.');
        } catch (InvalidPayloadException) {
            self::assertTrue(true);
        }

        // 2) Tampered cursor rejected
        $tampered = FeedCursor::fromWire(strrev($page1->nextCursor->toWire()) === $page1->nextCursor->toWire() ? 'a' : base64_encode('{"published_at":"2026-01-01T00:00:00.000000+00:00","id":"'.$p1->resource?->id.'","mac":"bad"}'));
        try {
            $feed->feed(new GetFeedQuery($surface, limit: 1, cursor: $tampered));
            self::fail('Tampered cursor must be rejected.');
        } catch (InvalidPayloadException) {
            self::assertTrue(true);
        }

        // 3) Cross-surface cursor rejected
        try {
            $feed->feed(new GetFeedQuery($otherSurface, limit: 1, cursor: $page1->nextCursor));
            self::fail('Cross-surface cursor must be rejected.');
        } catch (InvalidPayloadException) {
            self::assertTrue(true);
        }

        // 4) Cross-viewer cursor rejected (Bob using Alice's cursor)
        $fake->actors->actAsPerson('bob');
        try {
            $feed->feed(new GetFeedQuery($surface, limit: 1, cursor: $page1->nextCursor));
            self::fail('Cross-viewer cursor must be rejected.');
        } catch (InvalidPayloadException) {
            self::assertTrue(true);
        }

        // 5) Expired cursor rejected
        $fake->actors->actAsPerson('alice');
        $expired = FeedCursor::issue(
            publishedAt: $page1->nextCursor->publishedAt,
            id: $page1->nextCursor->id,
            surface: $surface,
            viewer: $this->app->make(ActorContexts::class)->current(),
            signingKey: $this->app->make(SockitRuntime::class)->cursorSigningKey(),
            expiresAt: new DateTimeImmutable('-10 seconds', new DateTimeZone('UTC')),
        );
        try {
            $feed->feed(new GetFeedQuery($surface, limit: 1, cursor: $expired));
            self::fail('Expired cursor must be rejected.');
        } catch (InvalidPayloadException) {
            self::assertTrue(true);
        }
    }

    public function test_surface_lifecycle_create_archive_cas_resume_delete_tombstone_and_outbox_events(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surfaces = $this->app->make(SurfaceService::class);
        $ref = new SurfaceRef(new PanelId('sockit.alpha'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'life-1'), Incarnation::initial());

        $created = $surfaces->create(new CommandKey('cmd-life-create-1'), $ref);
        self::assertSame(1, $created->revision->value);

        // Stale archive CAS fails
        try {
            $surfaces->archive(new CommandKey('cmd-life-arch-stale'), $ref, new Revision(99));
            self::fail('Stale revision on archive must fail.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::RevisionConflict, $e->problem());
        }

        // Archive rev 1 -> rev 2
        $archived = $surfaces->archive(new CommandKey('cmd-life-arch-ok-1'), $ref, new Revision(1));
        self::assertTrue($archived->isArchived());
        self::assertSame(2, $archived->revision->value);

        // Resume rev 2 -> rev 3 Active
        $resumed = $surfaces->resume(new CommandKey('cmd-life-resume-1'), $ref, new Revision(2));
        self::assertFalse($resumed->isArchived());
        self::assertSame(3, $resumed->revision->value);

        // Delete rev 3 -> rev 4 Deleted + tombstone + Deleted->Active forbidden!
        $deleted = $surfaces->delete(new CommandKey('cmd-life-delete-1'), $ref, new Revision(3));
        self::assertTrue($deleted->isDeleted());
        self::assertSame(4, $deleted->revision->value);
        self::assertSame(1, DB::table('sk_resource_tombstones')->where('resource_id', $deleted->id)->count());

        try {
            $surfaces->resume(new CommandKey('cmd-life-resurrect-1'), $ref, new Revision(4));
            self::fail('Deleted -> Active on same incarnation must be forbidden.');
        } catch (SurfaceException $e) {
            self::assertSame(SockitErrorCode::ContentDeleted, $e->problem());
        }

        // Verify atomic outbox events emitted across create/archive/resume/delete
        $eventTypes = DB::table('sk_outbox_events')->orderBy('occurred_at')->pluck('event_type')->all();
        self::assertContains('sockit.surfaces.created', $eventTypes);
        self::assertContains('sockit.surfaces.archived', $eventTypes);
        self::assertContains('sockit.surfaces.resumed', $eventTypes);
        self::assertContains('sockit.surfaces.deleted', $eventTypes);
    }

    public function test_php84_check_works_in_read_only_cold_copy(): void
    {
        $tmpDir = sys_get_temp_dir().'/sockit-ro-check-'.bin2hex(random_bytes(4));
        mkdir($tmpDir.'/bin', 0755, true);
        copy(dirname(__DIR__, 4).'/bin/php84', $tmpDir.'/bin/php84');
        chmod($tmpDir.'/bin/php84', 0555);
        chmod($tmpDir.'/bin', 0555);
        chmod($tmpDir, 0555);

        try {
            $output = [];
            $code = 1;
            exec(escapeshellarg($tmpDir.'/bin/php84').' --check 2>&1', $output, $code);
            self::assertSame(0, $code, implode("\n", $output));
            self::assertFalse(is_dir($tmpDir.'/build'));
        } finally {
            chmod($tmpDir, 0755);
            chmod($tmpDir.'/bin', 0755);
            @unlink($tmpDir.'/bin/php84');
            @rmdir($tmpDir.'/bin');
            @rmdir($tmpDir);
        }
    }
}
