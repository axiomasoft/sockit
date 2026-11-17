<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Modules\Reactions\Data\RemoveReactionCommand;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testing\Fakes\Operations\SpyOutboxConsumer;
use SocKit\Testkit\SocKitTestCase;

final class OutboxAndReactionRevisionTest extends SocKitTestCase
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

    public function test_reaction_outbox_identities_are_type_distinct_and_rebuild_preserves_monotonic_revision(): void
    {
        $this->migrateSockitSchema();
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $panelId = new PanelId('sockit.alpha');
        $surface = new SurfaceRef($panelId, new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'react-rev-1'), Incarnation::initial());

        $posts = $this->app->make(PostService::class);
        $reactions = $this->app->make(ReactionService::class);

        $post = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-react-rev-post-1'),
            surface: $surface,
            body: 'React target',
            audience: AudienceSpec::platform(),
        ));
        $target = new HostEntityRef('post', (string) $post->resource?->id);

        // #13a: Distinct reaction types on the SAME target generate distinct outbox event IDs without EventIdentityConflict
        $addLike1 = $reactions->add(new AddReactionCommand(new CommandKey('cmd-react-rev-like-1'), $surface, $target, 'like'));
        $addLove1 = $reactions->add(new AddReactionCommand(new CommandKey('cmd-react-rev-love-1'), $surface, $target, 'love'));
        self::assertTrue($addLike1->isSuccess());
        self::assertTrue($addLove1->isSuccess());

        $remLike1 = $reactions->remove(new RemoveReactionCommand(new CommandKey('cmd-react-rev-unlk-1'), $surface, $target, 'like'));
        self::assertTrue($remLike1->isSuccess());

        $addLike2 = $reactions->add(new AddReactionCommand(new CommandKey('cmd-react-rev-like-2'), $surface, $target, 'like'));
        self::assertTrue($addLike2->isSuccess());

        $likeBucketBefore = DB::table('sk_reaction_counts')
            ->where('panel_id', 'sockit.alpha')
            ->where('target_key', $target->value)
            ->where('reaction_type', 'like')
            ->first();
        self::assertNotNull($likeBucketBefore);
        $revBefore = (int) $likeBucketBefore->source_revision;
        self::assertSame(3, $revBefore);

        $eventCount = DB::table('sk_outbox_events')
            ->whereIn('event_type', ['sockit.reactions.added', 'sockit.reactions.removed'])
            ->count();
        self::assertSame(4, $eventCount);

        // #13b: RebuildReactionsCommand MUST NOT reset source_revision back to 1
        $this->artisan('sockit:reactions:rebuild', ['--panel' => 'sockit.alpha'])->assertExitCode(0);

        $likeBucketAfter = DB::table('sk_reaction_counts')
            ->where('panel_id', 'sockit.alpha')
            ->where('target_key', $target->value)
            ->where('reaction_type', 'like')
            ->first();
        self::assertNotNull($likeBucketAfter);
        self::assertSame($revBefore, (int) $likeBucketAfter->source_revision);

        // Subsequent mutation advances revision monotonically without outbox aggregate_revision collision
        $fake->actors->actAsPerson('bob');
        $bobLike = $reactions->add(new AddReactionCommand(new CommandKey('cmd-r-like-bob-1'), $surface, $target, 'like'));
        self::assertTrue($bobLike->isSuccess());
        self::assertSame($revBefore + 1, $bobLike->revision?->value);
    }

    public function test_outbox_durable_leases_and_independent_projection_only_replay(): void
    {
        $this->migrateSockitSchema();
        $spy = new SpyOutboxConsumer;
        $this->app->instance(SpyOutboxConsumer::class, $spy);
        $this->app->tag([SpyOutboxConsumer::class], OutboxConsumer::class);

        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $panelId = new PanelId('sockit.alpha');
        $surface = new SurfaceRef($panelId, new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'outbox-1'), Incarnation::initial());

        $posts = $this->app->make(PostService::class);
        $relay = $this->app->make(OutboxRelay::class);

        $pub = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-outbox-lease-post-1'),
            surface: $surface,
            body: 'Outbox lease & projection test',
            audience: AudienceSpec::platform(),
        ));
        $postId = (string) $pub->resource?->id;

        $eventRow = DB::table('sk_outbox_events')->where('aggregate_id', $postId)->first();
        $surfaceEventRow = DB::table('sk_outbox_events')->where('event_type', 'sockit.surfaces.created')->first();
        self::assertNotNull($eventRow);
        self::assertNotNull($surfaceEventRow);
        self::assertNull($eventRow->delivered_at);
        self::assertNull($eventRow->projection_replayed_at);
        self::assertNull($eventRow->lease_owner);

        // #14: Active lease held by another worker blocks claim until expired
        $future = new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        DB::table('sk_outbox_events')->whereIn('id', [$eventRow->id, $surfaceEventRow->id])->update([
            'lease_owner' => 'worker-other',
            'lease_until' => $future,
        ]);
        self::assertSame(0, $relay->relayPending($panelId));
        self::assertCount(0, $spy->recorded());

        // Retire surface event out of this test's post-lease focus after verifying lease contention
        $nowStr = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        DB::table('sk_outbox_events')->where('id', $surfaceEventRow->id)->update([
            'lease_owner' => null,
            'lease_until' => null,
            'delivered_at' => $nowStr,
            'projection_replayed_at' => $nowStr,
        ]);

        // Expire lease -> claim succeeds cleanly, unless consumer fails (then lease is released and attempts incremented)
        $past = new DateTimeImmutable('-5 minutes', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        DB::table('sk_outbox_events')->where('id', $eventRow->id)->update([
            'lease_until' => $past,
        ]);

        // #15: ProjectionOnly runs independently WITHOUT marking delivered_at!
        $this->artisan('sockit:outbox:run', ['--panel' => 'sockit.alpha', '--replay-mode' => 'projection_only'])->assertExitCode(0);
        self::assertCount(1, $spy->recorded());
        self::assertSame(ReplayMode::ProjectionOnly, $spy->recorded()[0]['mode']);

        $afterProjection = DB::table('sk_outbox_events')->where('id', $eventRow->id)->first();
        self::assertNotNull($afterProjection->projection_replayed_at);
        self::assertNull($afterProjection->delivered_at);
        self::assertNull($afterProjection->projection_lease_owner);

        // Re-running projection_only does NOT double-deliver projection replay
        $this->artisan('sockit:outbox:run', ['--panel' => 'sockit.alpha', '--replay-mode' => 'projection_only'])->assertExitCode(0);
        self::assertCount(1, $spy->recorded());

        // Consumer fails once during Normal delivery -> lease released cleanly, attempts=1, not yet delivered
        $spy->failNext();
        self::assertSame(0, $relay->relayPending($panelId));
        $afterFail = DB::table('sk_outbox_events')->where('id', $eventRow->id)->first();
        self::assertNull($afterFail->delivered_at);
        self::assertNull($afterFail->lease_owner);
        self::assertSame(1, (int) $afterFail->attempts);
        self::assertNotNull($afterFail->last_problem_code);

        // Normal delivery still runs because ProjectionOnly did NOT consume normal delivery!
        $this->artisan('sockit:outbox:run', ['--panel' => 'sockit.alpha', '--replay-mode' => 'normal'])->assertExitCode(0);
        self::assertCount(2, $spy->recorded());
        self::assertSame(ReplayMode::Normal, $spy->recorded()[1]['mode']);

        $afterNormal = DB::table('sk_outbox_events')->where('id', $eventRow->id)->first();
        self::assertNotNull($afterNormal->delivered_at);
        self::assertNull($afterNormal->lease_owner);
        self::assertNull($afterNormal->last_problem_code);
        self::assertSame(2, (int) $afterNormal->attempts);
    }
}
