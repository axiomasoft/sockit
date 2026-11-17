<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\BetaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Core\Tests\Fixtures\PanelBetaProvider;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Data\AddGroupMemberCommand;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Enums\GroupMemberRole;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\EditPostCommand;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Posts\Data\TransitionPostStateCommand;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Modules\Reactions\Data\RemoveReactionCommand;
use SocKit\Operations\Data\SafetyVerdict;
use SocKit\Operations\Enums\OperationOutcome;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Exceptions\SurfaceException;
use SocKit\Testkit\SocKitTestCase;

final class SecurityBoundariesAndModulesTest extends SocKitTestCase
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

    public function test_mandatory_write_security_checks_on_surfaces_posts_groups_and_reactions(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->consent->allowAll();

        $alice = new ActorRef('user', 'alice');
        $team = new HostEntityRef('team', 'eng');
        $fake->contours->set($alice, 'team', $team, ContourMembership::Live);
        $fake->actors->actAsPerson('alice');

        $panelId = new PanelId('sockit.alpha');
        $kindId = new SurfaceKindId('sockit.wall');
        $host = new HostEntityRef('board', 'sec-1');
        $surface = new SurfaceRef($panelId, $kindId, $host, Incarnation::initial());

        $surfaces = $this->app->make(SurfaceService::class);
        $posts = $this->app->make(PostService::class);
        $groups = $this->app->make(GroupService::class);
        $reactions = $this->app->make(ReactionService::class);

        // #2a: SurfaceService::ensureInstance & archive require ActionAuthorizer + write admission
        $fake->authorizer->deny('sockit.surface.ensure', 'no.surface.create');
        try {
            $surfaces->ensureInstance(new CommandKey('cmd-surf-denied-0001'), $panelId, $kindId, $host);
            self::fail('Expected AuthorizationDeniedException.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame(SockitErrorCode::AuthorizationDenied, $e->problem());
        }

        $fake->authorizer->reset();
        $surfaces->ensureInstance(new CommandKey('cmd-surf-allow-00001'), $panelId, $kindId, $host);

        $fake->surfacePolicy->readOnlyAdmission('surface.read_only');
        try {
            $surfaces->archive(new CommandKey('cmd-surf-ro-archive-1'), $surface, new Revision(1));
            self::fail('Expected OperationException AudienceDenied on read-only archive.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        $fake->surfacePolicy->allowAll();
        $fake->authorizer->deny('sockit.surface.archive', 'no.archive');
        try {
            $surfaces->archive(new CommandKey('cmd-surf-denied-archive-1'), $surface, new Revision(1));
            self::fail('Expected AuthorizationDeniedException on archive.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame(SockitErrorCode::AuthorizationDenied, $e->problem());
        }

        // #2b: GroupService::create requires ActionAuthorizer + write admission
        $fake->authorizer->reset();
        $fake->surfacePolicy->readOnlyAdmission('groups.read_only');
        try {
            $groups->create(new CreateGroupCommand(new CommandKey('cmd-group-create-ro1'), $surface, 'team-ro', 'Team RO'));
            self::fail('Expected AudienceDenied on group create with read-only admission.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        $fake->surfacePolicy->allowAll();
        $fake->authorizer->deny('sockit.groups.create', 'no.group.create');
        try {
            $groups->create(new CreateGroupCommand(new CommandKey('cmd-grp-denied-0001'), $surface, 'team-deny', 'Team Deny'));
            self::fail('Expected AuthorizationDeniedException on group create.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame(SockitErrorCode::AuthorizationDenied, $e->problem());
        }

        // #2c: Publish contour post, then revoke contour / degrade admission -> edit without replacement audience fails!
        $fake->authorizer->reset();
        $fake->surfacePolicy->allowAll();
        $published = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-edit-pub-000001'),
            surface: $surface,
            body: 'Initial contour markdown post',
            bodyFormat: 'markdown',
            audience: AudienceSpec::contour('team', $team),
        ));
        self::assertTrue($published->isSuccess());
        $postId = (string) $published->resource?->id;

        $fake->surfacePolicy->readOnlyAdmission('surface.read_only');
        try {
            $posts->edit(new EditPostCommand(
                key: new CommandKey('cmd-edit-ro-0000001'),
                surface: $surface,
                postId: $postId,
                expectedRevision: new Revision(1),
                body: 'Edit must be rejected on read-only admission',
            ));
            self::fail('Expected AudienceDenied on edit during read-only admission.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        try {
            $posts->transition(new TransitionPostStateCommand(
                key: new CommandKey('cmd-trans-ro-000001'),
                surface: $surface,
                postId: $postId,
                expectedRevision: new Revision(1),
                targetState: ContentState::Hidden,
                reasonCode: 'mod.hide',
            ));
            self::fail('Expected AudienceDenied on transition during read-only admission.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        // Revoke containment while keeping admission open -> edit omitting $audience MUST still recheck containment!
        $fake->surfacePolicy->allowAll();
        $fake->surfacePolicy->denyContainment('surface.outside_boundary');
        try {
            $posts->edit(new EditPostCommand(
                key: new CommandKey('cmd-edit-containment-1'),
                surface: $surface,
                postId: $postId,
                expectedRevision: new Revision(1),
                body: 'Edit without replacement audience',
            ));
            self::fail('Expected AudienceDenied on edit when outside surface containment.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        $fake->surfacePolicy->allowAll();
        try {
            $posts->edit(new EditPostCommand(
                key: new CommandKey('cmd-edit-floor-wide-001'),
                surface: $surface,
                postId: $postId,
                expectedRevision: new Revision(1),
                body: 'Edit widening beyond platform floor',
                audience: AudienceSpec::public(),
            ));
            self::fail('Expected AudienceNotContained on public audience beyond floor.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceNotContained, $e->problem());
        }

        // #2d: Reactions require write admission & write target visibility (AllowReadOnly rejected!)
        $fake->surfacePolicy->allowAll();
        $fake->surfacePolicy->readOnlyAdmission('reactions.read_only');
        $postTarget = new HostEntityRef('post', $postId);

        try {
            $reactions->add(new AddReactionCommand(new CommandKey('cmd-react-ro-add-1'), $surface, $postTarget, 'like'));
            self::fail('Expected AudienceDenied on reaction add during read-only admission.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        $fake->surfacePolicy->allowAll();
        $reactions->add(new AddReactionCommand(new CommandKey('cmd-react-ok-add-1'), $surface, $postTarget, 'like'));

        $fake->surfacePolicy->readOnlyAdmission('reactions.read_only');
        try {
            $reactions->remove(new RemoveReactionCommand(new CommandKey('cmd-react-ro-rem-1'), $surface, $postTarget, 'like'));
            self::fail('Expected AudienceDenied on reaction remove during read-only admission.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }
    }

    public function test_panel_scoped_group_membership_isolation_and_fail_closed_private_group_reads(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->consent->allowAll();

        $alphaPanel = new PanelId('sockit.alpha');
        $betaPanel = new PanelId('sockit.beta');
        $kindId = new SurfaceKindId('sockit.wall');
        $host = new HostEntityRef('board', 'iso-1');
        $alphaSurface = new SurfaceRef($alphaPanel, $kindId, $host, Incarnation::initial());
        $betaSurface = new SurfaceRef($betaPanel, $kindId, $host, Incarnation::initial());

        $groups = $this->app->make(GroupService::class);
        $posts = $this->app->make(PostService::class);
        $resolver = $this->app->make(GroupMembershipResolver::class);

        // Alice creates 'staff' on sockit.alpha and publishes to it; Bob creates 'staff' on sockit.beta and publishes to it
        $fake->actors->actAsPerson('alice');
        $alphaGroupRes = $groups->create(new CreateGroupCommand(
            key: new CommandKey('cmd-alpha-grp-0001'),
            surface: $alphaSurface,
            slug: 'staff',
            title: 'Alpha Staff',
            visibility: GroupVisibility::Private,
        ));
        $alphaGroupId = (string) $alphaGroupRes->resource?->id;
        $groups->addMember(new AddGroupMemberCommand(
            key: new CommandKey('cmd-alpha-grp-add-alice'),
            surface: $alphaSurface,
            groupId: $alphaGroupId,
            expectedRevision: new Revision(1),
            member: new ActorRef('user', 'alice'),
            role: GroupMemberRole::Owner,
        ));

        $alphaStaffPost = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-alpha-post-001'),
            surface: $alphaSurface,
            body: 'Alpha secret',
            audience: AudienceSpec::group(new GroupRef('staff')),
        ));
        self::assertTrue($alphaStaffPost->isSuccess());

        $fake->actors->actAsPerson('bob');
        $betaGroupRes = $groups->create(new CreateGroupCommand(
            key: new CommandKey('cmd-beta-grp-00001'),
            surface: $betaSurface,
            slug: 'staff',
            title: 'Beta Staff',
            visibility: GroupVisibility::Private,
        ));
        $betaGroupId = (string) $betaGroupRes->resource?->id;
        $groups->addMember(new AddGroupMemberCommand(
            key: new CommandKey('cmd-beta-grp-add-bob'),
            surface: $betaSurface,
            groupId: $betaGroupId,
            expectedRevision: new Revision(1),
            member: new ActorRef('user', 'bob'),
            role: GroupMemberRole::Owner,
        ));

        $betaStaffPost = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-beta-post-0001'),
            surface: $betaSurface,
            body: 'Beta secret',
            audience: AudienceSpec::group(new GroupRef('staff')),
        ));
        self::assertTrue($betaStaffPost->isSuccess());

        // #3: Switch back to Alice -> unscoped isMember() fails closed, Alpha 'staff' succeeds, Beta 'staff' DENIED!
        $fake->actors->actAsPerson('alice');
        $aliceCtx = $this->app->make(ActorContexts::class)->current();
        self::assertFalse($resolver->isMember($aliceCtx, new GroupRef('staff')));

        self::assertSame('Alpha secret', $posts->get($alphaSurface, (string) $alphaStaffPost->resource?->id)->body);

        // Lookup by UUID ALSO respects panel boundary
        $alphaUuidPost = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-alpha-uuid-post'),
            surface: $alphaSurface,
            body: 'Alpha UUID post',
            audience: AudienceSpec::group(new GroupRef($alphaGroupId)),
        ));
        self::assertSame('Alpha UUID post', $posts->get($alphaSurface, (string) $alphaUuidPost->resource?->id)->body);

        try {
            $posts->get($betaSurface, (string) $betaStaffPost->resource?->id);
            self::fail('Alice must NOT read Beta group post using identical slug.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        self::assertNotSame($alphaGroupId, $betaGroupId);

        // #4: GroupService::get fails closed for non-member Bob on alphaGroupId, and fails closed on missing surface def!
        self::assertSame('Alpha Staff', $groups->get($alphaPanel, $alphaGroupId)->title);

        $fake->actors->actAsPerson('bob');
        try {
            $groups->get($alphaPanel, $alphaGroupId);
            self::fail('Non-member Bob must not read private Alpha group.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        // Deny admission -> fails closed even for Alpha member
        $fake->actors->actAsPerson('alice');
        $fake->surfacePolicy->denyAdmission('surface.blocked');
        try {
            $groups->get($alphaPanel, $alphaGroupId);
            self::fail('Private group read must fail when surface admission denies.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        // Unregistered/missing surface_kind on private group row MUST fail closed!
        $fake->surfacePolicy->allowAll();
        $orphanId = Ids::uuid7();
        DB::table('sk_groups')->insert([
            'id' => $orphanId,
            'panel_id' => 'sockit.alpha',
            'surface_kind' => 'sockit.missing-surface',
            'surface_host_type' => 'board',
            'surface_host_value' => 'orphan',
            'surface_incarnation' => 1,
            'slug' => 'orphan-priv',
            'title' => 'Orphan Private',
            'visibility' => GroupVisibility::Private->value,
            'state' => 'active',
            'revision' => 1,
            'created_by_type' => 'user',
            'created_by_id' => 'alice',
            'created_at' => gmdate('Y-m-d H:i:s.uP'),
            'updated_at' => gmdate('Y-m-d H:i:s.uP'),
        ]);
        try {
            $groups->get($alphaPanel, $orphanId);
            self::fail('Private group without compiled surface definition must fail closed.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }
    }

    public function test_minor_default_privacy_edit_body_format_hold_outbox_and_replay_lifecycle_guards(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->consent->allowAll();

        $surface = new SurfaceRef(
            panel: new PanelId('sockit.alpha'),
            kind: new SurfaceKindId('sockit.wall'),
            host: new HostEntityRef('board', 'sec-2'),
            incarnation: Incarnation::initial(),
        );
        $posts = $this->app->make(PostService::class);
        $surfaces = $this->app->make(SurfaceService::class);

        // #8: Minor with null audience defaults to minorDefaultScope (direct), NOT adult defaultScope (platform)
        $fake->actors->actAsPerson('minor-alice', ageBand: AgeBand::Minor);
        $minorRes = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-minor-default-1'),
            surface: $surface,
            body: 'Minor default post',
        ));
        self::assertTrue($minorRes->isSuccess());
        $minorView = $posts->get($surface, (string) $minorRes->resource?->id);
        self::assertSame(Scope::Direct, $minorView->audience->scope);

        // #17 & #16: Publish markdown post; edit omitting bodyFormat checks safety with 'markdown' and emits held state_changed outbox event
        $fake->actors->actAsPerson('alice');
        $observedFormat = null;
        $fake->safety->using(static function ($actor, $surf, string $contentType, string $body, array $metadata) use (&$observedFormat): SafetyVerdict {
            $observedFormat = $metadata['format'] ?? null;

            return str_contains($body, 'hold-me') ? SafetyVerdict::hold('policy.moderation') : SafetyVerdict::allow();
        });

        $pub = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-md-pub-0000001'),
            surface: $surface,
            body: '# Clean heading',
            bodyFormat: 'markdown',
            audience: AudienceSpec::platform(),
        ));
        self::assertTrue($pub->isSuccess());
        self::assertSame('markdown', $observedFormat);
        $postId = (string) $pub->resource?->id;

        $observedFormat = null;
        $heldEdit = $posts->edit(new EditPostCommand(
            key: new CommandKey('cmd-md-edit-hold-1'),
            surface: $surface,
            postId: $postId,
            expectedRevision: new Revision(1),
            body: '# hold-me content',
        ));
        self::assertSame(OperationOutcome::Rejected, $heldEdit->outcome);
        self::assertSame(SockitErrorCode::ContentHeld, $heldEdit->problem);
        self::assertSame('markdown', $observedFormat);

        $stateChangedEvent = DB::table('sk_outbox_events')
            ->where('aggregate_id', $postId)
            ->where('event_type', 'sockit.posts.state_changed')
            ->first();
        self::assertNotNull($stateChangedEvent);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $stateChangedEvent->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('published', $payload['previous_state'] ?? null);
        self::assertSame('held', $payload['new_state'] ?? null);

        // #12: Replay guards reject stale receipts after deletion, erasure, archival, or reincarnation
        $fake->safety->allowAll();
        $replayPubKey = new CommandKey('cmd-replay-guard-pub-1');
        $replayCmd = new PublishPostCommand(
            key: $replayPubKey,
            surface: $surface,
            body: 'Replay guarded post',
            audience: AudienceSpec::platform(),
        );
        $created = $posts->publish($replayCmd);
        self::assertTrue($created->isSuccess());
        $replayPostId = (string) $created->resource?->id;

        // First replay on active live resource succeeds idempotently
        self::assertSame($replayPostId, $posts->publish($replayCmd)->resource?->id);

        // Delete -> replay throws ContentDeleted
        $posts->transition(new TransitionPostStateCommand(
            key: new CommandKey('cmd-replay-guard-del-1'),
            surface: $surface,
            postId: $replayPostId,
            expectedRevision: new Revision(1),
            targetState: ContentState::Deleted,
        ));
        try {
            $posts->publish($replayCmd);
            self::fail('Idempotent replay of publish for deleted post must fail.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ContentDeleted, $e->problem());
        }

        // Surface archive -> replay of live post publish fails closed with SurfaceException!
        $livePubKey = new CommandKey('cmd-replay-surf-pub-1');
        $liveCmd = new PublishPostCommand($livePubKey, $surface, 'Live before archive', audience: AudienceSpec::platform());
        self::assertTrue($posts->publish($liveCmd)->isSuccess());

        $surfaces->archive(new CommandKey('cmd-replay-archive-1'), $surface, new Revision(1));
        try {
            $posts->publish($liveCmd);
            self::fail('Replay after surface archive must fail closed.');
        } catch (SurfaceException) {
            self::assertTrue(true);
        }

        // Reincarnate -> replay targeting old incarnation also fails closed
        $reincarnated = $surfaces->startNewIncarnation(
            new CommandKey('cmd-replay-reinc-1'),
            $surface->panel,
            $surface->kind,
            $surface->host,
        );
        self::assertSame(2, $reincarnated->incarnation->value);
        try {
            $posts->publish($liveCmd);
            self::fail('Replay targeting archived incarnation 1 must fail closed.');
        } catch (SurfaceException) {
            self::assertTrue(true);
        }

        // Erase post on new incarnation & check replay rejection
        $inc2Surface = $reincarnated->ref();
        $inc2Key = new CommandKey('cmd-replay-inc2-pub-1');
        $inc2Cmd = new PublishPostCommand($inc2Key, $inc2Surface, 'To be erased', audience: AudienceSpec::platform());
        $inc2Res = $posts->publish($inc2Cmd);
        $inc2PostId = (string) $inc2Res->resource?->id;
        $posts->transition(new TransitionPostStateCommand(
            key: new CommandKey('cmd-replay-inc2-erase-1'),
            surface: $inc2Surface,
            postId: $inc2PostId,
            expectedRevision: new Revision(1),
            targetState: ContentState::Erased,
        ));
        try {
            $posts->publish($inc2Cmd);
            self::fail('Replay after post erasure must throw ContentErased.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ContentErased, $e->problem());
        }

        self::assertInstanceOf(InstallationRef::class, $this->app->make(ActorContexts::class)->current()->installation);
    }
}
