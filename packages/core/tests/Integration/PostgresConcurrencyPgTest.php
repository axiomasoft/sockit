<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Integration;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use LogicException;
use Override;
use Packman\Plugins\Contracts\Contributions\Extensions;
use Packman\Schema\Data\Compiler\ConstraintNames;
use Packman\Testkit\Concurrency\Postgres\PostgresBarrier;
use Packman\Testkit\Concurrency\Process\Concurrency;
use Packman\Testkit\Data\Concurrency\ConcurrencyOutcome;
use Packman\Testkit\Exceptions\Database\UnsafeTestDatabaseException;
use PHPUnit\Framework\Attributes\Group;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Core\Tests\Fixtures\PanelPgProvider;
use SocKit\Core\Tests\Fixtures\PgWallSurfaceProvider;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\EditPostCommand;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Posts\Data\TransitionPostStateCommand;
use SocKit\Modules\Posts\Enums\ContentState;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\OutboxEventRecord;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Testing\Fakes\Operations\SpyOutboxConsumer;
use SocKit\Testing\SocKitFake;
use SocKit\Testkit\SocKitTestCase;

#[Group('pg')]
final class PostgresConcurrencyPgTest extends SocKitTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'pgsql');
        $config->set('sockit.storage.connection', 'pgsql');
    }

    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [PanelPgProvider::class];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [PgWallSurfaceProvider::class];
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertDestructiveSuitePgsqlAllowed('pgsql');

        DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS tenant_alpha CASCADE');
        DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS tenant_beta CASCADE');
        DB::connection('pgsql')->getSchemaBuilder()->dropAllTables();
        $this->migrateSockitSchema('pgsql');
    }

    public function test_postgresql_constraints_naming_check_guards_and_atomic_tx_rollback(): void
    {
        $constraints = DB::connection('pgsql')->select(
            "SELECT conname, contype FROM pg_constraint WHERE connamespace = 'public'::regnamespace AND conname LIKE 'sk_%'",
        );

        self::assertNotEmpty($constraints);

        foreach ($constraints as $constraint) {
            $name = (string) $constraint->conname;
            self::assertLessThanOrEqual(ConstraintNames::MAX_IDENTIFIER, strlen($name), "Constraint {$name} exceeds 63 bytes.");
            $suffix = match ((string) $constraint->contype) {
                'p' => '_pk',
                'f' => '_fk',
                'u' => '_uq',
                'c' => '_ck',
                default => '',
            };
            if ($suffix !== '') {
                $matchesNaming = str_ends_with($name, $suffix)
                    || (strlen($name) === ConstraintNames::MAX_IDENTIFIER && preg_match('/_[0-9a-f]{8}\z/', $name) === 1);
                self::assertTrue($matchesNaming, "Constraint [{$name}] does not match {$suffix} or shortened 63-byte hash naming.");
            }
        }

        // Verify PostgreSQL CHECK guard rejects erased post with non-null current_body
        $surface = new SurfaceRef(new PanelId('sockit.pg'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'pg-1'), Incarnation::initial());
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $instance = $this->app->make(SurfaceService::class)->ensureInstance(new CommandKey('cmd-pg-check-surf-0001'), $surface->panel, $surface->kind, $surface->host);

        try {
            DB::connection('pgsql')->table('sk_posts')->insert([
                'id' => Ids::uuid7(),
                'panel_id' => 'sockit.pg',
                'surface_instance_id' => $instance->id,
                'surface_incarnation' => 1,
                'author_type' => 'user',
                'author_id' => 'alice',
                'state' => 'erased',
                'body_format' => 'plain',
                'current_body' => 'MUST NOT EXIST WHEN ERASED',
                'revision' => 1,
                'privacy_revision' => 1,
                'audience_wire' => json_encode(AudienceSpec::platform()->toWire(), JSON_THROW_ON_ERROR),
                'created_at' => gmdate('Y-m-d H:i:s.uP'),
                'updated_at' => gmdate('Y-m-d H:i:s.uP'),
            ]);
            self::fail('PostgreSQL CHECK constraint erased_body_null should reject non-null current_body.');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->errorInfo[0] ?? null);
        }

        // #20: Atomic rollback using valid FK & audience_wire verifies post write actually happened then rolled back cleanly
        $runner = $this->app->make(OperationEnvelopeRunner::class);
        $envelope = new CommandEnvelope(
            key: new CommandKey('cmd-pg-rollback-test-1'),
            namespace: new CommandNamespace(new InstallationRef('inst-1'), $surface->panel, 'sockit.posts', 'posts.publish'),
            digest: PayloadDigest::of('posts.publish', ['body' => 'boom']),
        );
        $rollbackPostId = Ids::uuid7();

        try {
            $runner->run(
                $envelope,
                $surface,
                'sockit.posts.publish',
                static function (): void {},
                static function ($actor, $connection) use ($instance, $rollbackPostId): array {
                    $connection->table('sk_posts')->insert([
                        'id' => $rollbackPostId,
                        'panel_id' => 'sockit.pg',
                        'surface_instance_id' => $instance->id,
                        'surface_incarnation' => 1,
                        'author_type' => 'user',
                        'author_id' => 'alice',
                        'state' => 'published',
                        'body_format' => 'plain',
                        'current_body' => 'Will rollback',
                        'revision' => 1,
                        'privacy_revision' => 1,
                        'audience_wire' => json_encode(AudienceSpec::platform()->toWire(), JSON_THROW_ON_ERROR),
                        'created_at' => gmdate('Y-m-d H:i:s.uP'),
                        'updated_at' => gmdate('Y-m-d H:i:s.uP'),
                    ]);

                    self::assertSame(1, $connection->table('sk_posts')->where('id', $rollbackPostId)->count());

                    throw new LogicException('Simulated post-write failure before commit.');
                },
            );
            self::fail('Expected LogicException.');
        } catch (LogicException $e) {
            self::assertSame('Simulated post-write failure before commit.', $e->getMessage());
            self::assertSame(0, DB::connection('pgsql')->table('sk_posts')->where('id', $rollbackPostId)->count());
            self::assertSame(0, DB::connection('pgsql')->table('sk_command_receipts')->where('command_key', 'cmd-pg-rollback-test-1')->count());
            self::assertSame(0, DB::connection('pgsql')->table('sk_outbox_events')->where('aggregate_type', 'post')->count());
            self::assertSame(1, DB::connection('pgsql')->table('sk_outbox_events')->where('event_type', 'sockit.surfaces.created')->count());
        }

        // #18: Guard refuses destructive PG reset whenever PACKMAN_TEST_DESTRUCTIVE_OPT_IN is not enabled
        $prevGetenv = getenv('PACKMAN_TEST_DESTRUCTIVE_OPT_IN');
        $hadEnv = array_key_exists('PACKMAN_TEST_DESTRUCTIVE_OPT_IN', $_ENV);
        $prevEnv = $_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] ?? null;
        $hadServer = array_key_exists('PACKMAN_TEST_DESTRUCTIVE_OPT_IN', $_SERVER);
        $prevServer = $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] ?? null;

        try {
            putenv('PACKMAN_TEST_DESTRUCTIVE_OPT_IN=0');
            $_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = '0';
            $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = '0';

            try {
                $this->assertDestructiveSuitePgsqlAllowed('pgsql');
                self::fail('Expected UnsafeTestDatabaseException when PACKMAN_TEST_DESTRUCTIVE_OPT_IN=0.');
            } catch (UnsafeTestDatabaseException) {
                self::assertTrue(true);
            }
        } finally {
            if ($prevGetenv === false) {
                putenv('PACKMAN_TEST_DESTRUCTIVE_OPT_IN');
            } else {
                putenv('PACKMAN_TEST_DESTRUCTIVE_OPT_IN='.$prevGetenv);
            }
            if ($hadEnv) {
                $_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = $prevEnv;
            } else {
                unset($_ENV['PACKMAN_TEST_DESTRUCTIVE_OPT_IN']);
            }
            if ($hadServer) {
                $_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN'] = $prevServer;
            } else {
                unset($_SERVER['PACKMAN_TEST_DESTRUCTIVE_OPT_IN']);
            }
        }
    }

    public function test_parallel_duplicate_command_key_race_commits_exactly_once_via_postgresql_savepoint(): void
    {
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.pg'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'pg-dup'), Incarnation::initial());
        $this->app->make(SurfaceService::class)->ensureInstance(new CommandKey('cmd-pg-dup-surf-00001'), $surface->panel, $surface->kind, $surface->host);

        $sharedKey = new CommandKey('cmd-pg-race-publish-01');
        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $sharedKey): array {
                DB::purge('pgsql');
                $res = $this->app->make(PostService::class)->publish(new PublishPostCommand(
                    key: $sharedKey,
                    surface: $surface,
                    body: 'Concurrent Same Key',
                    audience: AudienceSpec::platform(),
                ));

                return $res->toWire();
            },
            function () use ($surface, $sharedKey): array {
                DB::purge('pgsql');
                $res = $this->app->make(PostService::class)->publish(new PublishPostCommand(
                    key: $sharedKey,
                    surface: $surface,
                    body: 'Concurrent Same Key',
                    audience: AudienceSpec::platform(),
                ));

                return $res->toWire();
            },
        ]);

        self::assertCount(2, $outcomes);
        self::assertTrue($outcomes[0]->succeeded, $outcomes[0]->errorMessage ?? 'worker 0 failed');
        self::assertTrue($outcomes[1]->succeeded, $outcomes[1]->errorMessage ?? 'worker 1 failed');

        /** @var array<string, mixed> $w0 */
        $w0 = $outcomes[0]->value;
        /** @var array<string, mixed> $w1 */
        $w1 = $outcomes[1]->value;
        self::assertSame('confirmed', $w0['outcome']);
        self::assertSame('confirmed', $w1['outcome']);
        self::assertSame($w0['resource']['id'], $w1['resource']['id']);

        self::assertSame(1, DB::connection('pgsql')->table('sk_posts')->count());
        self::assertSame(1, DB::connection('pgsql')->table('sk_command_receipts')->where('command_key', $sharedKey->value)->count());
        self::assertSame(1, DB::connection('pgsql')->table('sk_outbox_events')->where('aggregate_type', 'post')->count());
        self::assertSame(1, DB::connection('pgsql')->table('sk_outbox_events')->where('event_type', 'sockit.surfaces.created')->count());

        // Same key with mismatched digest throws IdempotencyConflict
        try {
            $this->app->make(PostService::class)->publish(new PublishPostCommand(
                key: $sharedKey,
                surface: $surface,
                body: 'Conflicting Payload!',
                audience: AudienceSpec::platform(),
            ));
            self::fail('Expected IdempotencyConflict.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::IdempotencyConflict, $e->problem());
        }
    }

    public function test_parallel_cas_edit_race_permits_only_one_winner_and_rejects_concurrent_loser(): void
    {
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.pg'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'pg-cas'), Incarnation::initial());
        $this->app->make(SurfaceService::class)->ensureInstance(new CommandKey('cmd-pg-cas-surf-00001'), $surface->panel, $surface->kind, $surface->host);

        $pub = $this->app->make(PostService::class)->publish(new PublishPostCommand(
            key: new CommandKey('cmd-pg-cas-create-001'),
            surface: $surface,
            body: 'Initial v1',
            audience: AudienceSpec::platform(),
        ));
        $postId = $pub->resource?->id ?? '';
        self::assertNotSame('', $postId);

        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $postId): array {
                DB::purge('pgsql');
                $res = $this->app->make(PostService::class)->edit(new EditPostCommand(
                    key: new CommandKey('cmd-pg-cas-edit-worker-a'),
                    surface: $surface,
                    postId: $postId,
                    expectedRevision: new Revision(1),
                    body: 'Edit from A',
                ));

                return $res->toWire();
            },
            function () use ($surface, $postId): array {
                DB::purge('pgsql');
                $res = $this->app->make(PostService::class)->edit(new EditPostCommand(
                    key: new CommandKey('cmd-pg-cas-edit-worker-b'),
                    surface: $surface,
                    postId: $postId,
                    expectedRevision: new Revision(1),
                    body: 'Edit from B',
                ));

                return $res->toWire();
            },
        ]);

        $succeeded = array_values(array_filter($outcomes, static fn (ConcurrencyOutcome $o): bool => $o->succeeded));
        $failed = array_values(array_filter($outcomes, static fn (ConcurrencyOutcome $o): bool => ! $o->succeeded));

        self::assertCount(1, $succeeded, 'Exactly one CAS writer must win.');
        self::assertCount(1, $failed, 'Exactly one CAS writer must hit RevisionConflict.');
        self::assertSame(OperationException::class, $failed[0]->errorClass);

        $row = DB::connection('pgsql')->table('sk_posts')->where('id', $postId)->first();
        self::assertSame(2, (int) $row->revision);
    }

    public function test_parallel_reaction_vs_hide_serializes_cleanly_on_postgresql_row_lock(): void
    {
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.pg'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'pg-rvh'), Incarnation::initial());
        $this->app->make(SurfaceService::class)->ensureInstance(new CommandKey('cmd-pg-rvh-surf-00001'), $surface->panel, $surface->kind, $surface->host);

        $pub = $this->app->make(PostService::class)->publish(new PublishPostCommand(
            key: new CommandKey('cmd-pg-rvh-create-001'),
            surface: $surface,
            body: 'Race reaction vs hide',
            audience: AudienceSpec::platform(),
        ));
        $postId = $pub->resource?->id ?? '';
        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $postId): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $fakeChild->actors->actAsPerson('bob');

                $res = $this->app->make(ReactionService::class)->add(new AddReactionCommand(
                    key: new CommandKey('cmd-pg-rvh-react-bob1'),
                    surface: $surface,
                    target: new HostEntityRef('post', $postId),
                    reactionType: 'like',
                ));

                return $res->toWire();
            },
            function () use ($surface, $postId): array {
                DB::purge('pgsql');
                $res = $this->app->make(PostService::class)->transition(new TransitionPostStateCommand(
                    key: new CommandKey('cmd-pg-rvh-hide-alice'),
                    surface: $surface,
                    postId: $postId,
                    expectedRevision: new Revision(1),
                    targetState: ContentState::Hidden,
                    reasonCode: 'mod.hide',
                ));

                return $res->toWire();
            },
        ]);

        // Hide ALWAYS commits; reaction either committed BEFORE hide or failed closed with ContentHidden (OperationException)!
        self::assertTrue($outcomes[1]->succeeded, $outcomes[1]->errorMessage ?? 'hide failed');

        if (! $outcomes[0]->succeeded) {
            self::assertSame(OperationException::class, $outcomes[0]->errorClass);
            self::assertSame(0, DB::connection('pgsql')->table('sk_reactions')->where('target_key', $postId)->count());
        } else {
            self::assertSame(1, DB::connection('pgsql')->table('sk_reactions')->where('target_key', $postId)->count());
        }

        $postRow = DB::connection('pgsql')->table('sk_posts')->where('id', $postId)->first();
        self::assertSame('hidden', (string) $postRow->state);
    }

    public function test_parallel_ensure_instance_absent_row_savepoint_race_and_outbox_skip_locked_leases(): void
    {
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $panelId = new PanelId('sockit.pg');
        $kindId = new SurfaceKindId('sockit.wall');
        $host = new HostEntityRef('board', 'pg-ensure-absent-race');
        $surface = new SurfaceRef($panelId, $kindId, $host, Incarnation::initial());

        DB::disconnect('pgsql');

        // #19: Concurrent ensureInstance on absent row creates exactly one surface instance via PG savepoint retry
        $ensureOutcomes = Concurrency::run([
            function () use ($panelId, $kindId, $host): string {
                DB::purge('pgsql');

                return $this->app->make(SurfaceService::class)
                    ->ensureInstance(new CommandKey('cmd-pg-ensure-race-a'), $panelId, $kindId, $host)
                    ->id;
            },
            function () use ($panelId, $kindId, $host): string {
                DB::purge('pgsql');

                return $this->app->make(SurfaceService::class)
                    ->ensureInstance(new CommandKey('cmd-pg-ensure-race-b'), $panelId, $kindId, $host)
                    ->id;
            },
        ]);

        self::assertCount(2, $ensureOutcomes);
        self::assertTrue($ensureOutcomes[0]->succeeded, $ensureOutcomes[0]->errorMessage ?? 'ensure 0 failed');
        self::assertTrue($ensureOutcomes[1]->succeeded, $ensureOutcomes[1]->errorMessage ?? 'ensure 1 failed');
        self::assertSame($ensureOutcomes[0]->value, $ensureOutcomes[1]->value);
        self::assertSame(1, DB::connection('pgsql')->table('sk_surface_instances')->where('host_entity_value', 'pg-ensure-absent-race')->count());

        // #14: Publish 4 posts, then run two PG outbox relay workers concurrently -> SKIP LOCKED delivers each event exactly once
        $posts = $this->app->make(PostService::class);
        for ($i = 1; $i <= 4; $i++) {
            self::assertTrue($posts->publish(new PublishPostCommand(
                key: new CommandKey("cmd-pg-skip-locked-post-{$i}"),
                surface: $surface,
                body: "Outbox worker item {$i}",
                audience: AudienceSpec::platform(),
            ))->isSuccess());
        }

        DB::disconnect('pgsql');

        $relayOutcomes = Concurrency::run([
            function () use ($panelId): array {
                DB::purge('pgsql');
                $spy = new class('worker-a') implements OutboxConsumer
                {
                    /** @var list<string> */
                    public array $ids = [];

                    public function __construct(private readonly string $key) {}

                    #[Override]
                    public function consumerKey(): string
                    {
                        return $this->key;
                    }

                    #[Override]
                    public function supports(string $eventType): bool
                    {
                        return str_starts_with($eventType, 'sockit.');
                    }

                    #[Override]
                    public function consume(OutboxEventRecord $event, ReplayMode $mode): void
                    {
                        $this->ids[] = $event->id;
                        usleep(60_000);
                    }
                };
                $this->app->instance(SpyOutboxConsumer::class, $spy);
                $this->app->tag([SpyOutboxConsumer::class], OutboxConsumer::class);
                $this->app->make(OutboxRelay::class)->relayPending($panelId, batchSize: 3, mode: ReplayMode::Normal);

                return $spy->ids;
            },
            function () use ($panelId): array {
                DB::purge('pgsql');
                $spy = new class('worker-b') implements OutboxConsumer
                {
                    /** @var list<string> */
                    public array $ids = [];

                    public function __construct(private readonly string $key) {}

                    #[Override]
                    public function consumerKey(): string
                    {
                        return $this->key;
                    }

                    #[Override]
                    public function supports(string $eventType): bool
                    {
                        return str_starts_with($eventType, 'sockit.');
                    }

                    #[Override]
                    public function consume(OutboxEventRecord $event, ReplayMode $mode): void
                    {
                        $this->ids[] = $event->id;
                        usleep(60_000);
                    }
                };
                $this->app->instance(SpyOutboxConsumer::class, $spy);
                $this->app->tag([SpyOutboxConsumer::class], OutboxConsumer::class);
                $this->app->make(OutboxRelay::class)->relayPending($panelId, batchSize: 3, mode: ReplayMode::Normal);

                return $spy->ids;
            },
        ]);

        self::assertCount(2, $relayOutcomes);
        self::assertTrue($relayOutcomes[0]->succeeded, $relayOutcomes[0]->errorMessage ?? 'relay 0 failed');
        self::assertTrue($relayOutcomes[1]->succeeded, $relayOutcomes[1]->errorMessage ?? 'relay 1 failed');

        /** @var list<string> $w0Ids */
        $w0Ids = $relayOutcomes[0]->value;
        /** @var list<string> $w1Ids */
        $w1Ids = $relayOutcomes[1]->value;
        $combined = array_merge($w0Ids, $w1Ids);

        self::assertCount(5, $combined);
        self::assertCount(5, array_unique($combined));
        self::assertSame(0, DB::connection('pgsql')->table('sk_outbox_events')->whereNull('delivered_at')->count());
        self::assertSame(0, DB::connection('pgsql')->table('sk_outbox_events')->whereNotNull('lease_owner')->count());
    }

    public function test_parallel_reaction_vs_audience_narrowing_rechecks_privacy_revision_under_row_lock(): void
    {
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $alice = new ActorRef('user', 'alice');
        $surface = new SurfaceRef(new PanelId('sockit.pg'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'pg-narrow'), Incarnation::initial());
        $this->app->make(SurfaceService::class)->ensureInstance(new CommandKey('cmd-pg-narrow-surf-1'), $surface->panel, $surface->kind, $surface->host);

        $pub = $this->app->make(PostService::class)->publish(new PublishPostCommand(
            key: new CommandKey('cmd-pg-narrow-create-1'),
            surface: $surface,
            body: 'Platform post about to narrow',
            audience: AudienceSpec::platform(),
        ));
        $postId = (string) $pub->resource?->id;

        $preBarrierName = 'pg-narrow-pre-'.Ids::uuid7();
        $commitBarrierName = 'pg-narrow-commit-'.Ids::uuid7();
        $setupPdo = DB::connection('pgsql')->getPdo();
        new PostgresBarrier($setupPdo, $preBarrierName, 2)->reset();
        new PostgresBarrier($setupPdo, $commitBarrierName, 2)->reset();
        DB::disconnect('pgsql');

        // #15: Bob deterministically passes pre-TX check (Platform, privacy_revision=1) and reaches precheck barrier;
        // Alice edits to Direct(alice) (privacy_revision=2) and hits commit barrier; Bob then enters TX and
        // fails closed under PG FOR UPDATE lock in TargetWriteFence::recheck()!
        $outcomes = Concurrency::run([
            function () use ($surface, $postId, $alice, $preBarrierName, $commitBarrierName): array {
                DB::purge('pgsql');
                $pdo = DB::connection('pgsql')->getPdo();
                new PostgresBarrier($pdo, $preBarrierName, 2)->arriveAndWait('alice', 5.0);

                $res = $this->app->make(PostService::class)->edit(new EditPostCommand(
                    key: new CommandKey('cmd-pg-narrow-edit-alice'),
                    surface: $surface,
                    postId: $postId,
                    expectedRevision: new Revision(1),
                    body: 'Narrowed to Alice only',
                    audience: AudienceSpec::direct($alice),
                ));

                new PostgresBarrier($pdo, $commitBarrierName, 2)->arriveAndWait('alice', 5.0);

                return $res->toWire();
            },
            function () use ($surface, $postId, $preBarrierName, $commitBarrierName): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $fakeChild->actors->actAsPerson('bob');

                $inner = null;
                foreach ($this->app->make(Extensions::class)->resolve(TargetResolver::class) as $candidate) {
                    if ($candidate->supports(new HostEntityRef('post', $postId))) {
                        $inner = $candidate;

                        break;
                    }
                }
                if (! $inner instanceof TargetWriteFence) {
                    throw new LogicException('Expected TargetWriteFence for post.');
                }

                $barrierFence = new class($inner, $preBarrierName, $commitBarrierName) implements TargetWriteFence
                {
                    private int $precheckCalls = 0;

                    public function __construct(
                        private readonly TargetWriteFence $inner,
                        private readonly string $preBarrierName,
                        private readonly string $commitBarrierName,
                    ) {}

                    #[Override]
                    public function supports(HostEntityRef $target): bool
                    {
                        return $this->inner->supports($target);
                    }

                    #[Override]
                    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
                    {
                        $resolution = $this->inner->resolve($actor, $surface, $target);
                        if (DB::connection('pgsql')->transactionLevel() === 0) {
                            $this->precheckCalls++;
                            if ($this->precheckCalls === 1) {
                                $pdo = DB::connection('pgsql')->getPdo();
                                new PostgresBarrier($pdo, $this->preBarrierName, 2)->arriveAndWait('bob', 5.0);
                                new PostgresBarrier($pdo, $this->commitBarrierName, 2)->arriveAndWait('bob', 5.0);
                            }
                        }

                        return $resolution;
                    }

                    #[Override]
                    public function recheck(
                        ActorContext $actor,
                        SurfaceRef $surface,
                        HostEntityRef $target,
                        TargetResolution $prechecked,
                    ): AuthorizationDecision {
                        return $this->inner->recheck($actor, $surface, $target, $prechecked);
                    }
                };
                $this->app->instance(TargetResolver::class, $barrierFence);

                $res = $this->app->make(ReactionService::class)->add(new AddReactionCommand(
                    key: new CommandKey('cmd-pg-narrow-react-bob'),
                    surface: $surface,
                    target: new HostEntityRef('post', $postId),
                    reactionType: 'like',
                ));

                return $res->toWire();
            },
        ]);

        self::assertTrue($outcomes[0]->succeeded, $outcomes[0]->errorMessage ?? 'edit failed');
        self::assertFalse($outcomes[1]->succeeded, 'Bob reaction must fail closed after audience narrowed under lock.');
        self::assertSame(OperationException::class, $outcomes[1]->errorClass);

        $postRow = DB::connection('pgsql')->table('sk_posts')->where('id', $postId)->first();
        self::assertSame(2, (int) $postRow->privacy_revision);
        self::assertSame(0, DB::connection('pgsql')->table('sk_reactions')->where('target_key', $postId)->count());
    }

    public function test_parallel_reaction_first_insert_and_rebuild_counts_serialize_cleanly_on_postgresql(): void
    {
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.pg'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'pg-rebuild-race'), Incarnation::initial());
        $this->app->make(SurfaceService::class)->ensureInstance(new CommandKey('cmd-pg-rebuild-surf-1'), $surface->panel, $surface->kind, $surface->host);

        $pub = $this->app->make(PostService::class)->publish(new PublishPostCommand(
            key: new CommandKey('cmd-pg-rebuild-post-1'),
            surface: $surface,
            body: 'Empty bucket target',
            audience: AudienceSpec::platform(),
        ));
        $postId = (string) $pub->resource?->id;

        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $postId): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $fakeChild->actors->actAsPerson('bob');

                $res = $this->app->make(ReactionService::class)->add(new AddReactionCommand(
                    key: new CommandKey('cmd-pg-rebuild-add-1'),
                    surface: $surface,
                    target: new HostEntityRef('post', $postId),
                    reactionType: 'like',
                ));

                return $res->toWire();
            },
            function () use ($surface): int {
                DB::purge('pgsql');
                $service = $this->app->make(ReactionService::class);
                $total = 0;
                for ($i = 0; $i < 3; $i++) {
                    $total += $service->rebuildCounts($surface->panel);
                }

                return $total;
            },
        ]);

        self::assertTrue($outcomes[0]->succeeded, $outcomes[0]->errorMessage ?? 'first reaction failed');
        self::assertTrue($outcomes[1]->succeeded, $outcomes[1]->errorMessage ?? 'rebuildCounts failed');

        $counts = $this->app->make(ReactionService::class)->counts($surface, new HostEntityRef('post', $postId));
        self::assertSame(['like' => 1], $counts->counts);
        self::assertSame(1, $counts->total);
    }
}
