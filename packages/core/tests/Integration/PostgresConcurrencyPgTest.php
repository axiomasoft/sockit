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
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Core\Tests\Fixtures\PanelPgProvider;
use SocKit\Core\Tests\Fixtures\PgWallSurfaceProvider;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Data\AddGroupMemberCommand;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Enums\GroupJoinPolicy;
use SocKit\Modules\Groups\Enums\GroupMemberRole;
use SocKit\Modules\Groups\Enums\GroupVisibility;
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
use SocKit\Operations\Data\SafetyVerdict;
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
        $barrierName = 'pg-dup-key-'.Ids::uuid7();
        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $barrierName, 2)->reset();
        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $sharedKey, $barrierName): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $calls = 0;
                $fakeChild->safety->using(static function () use ($barrierName, &$calls): SafetyVerdict {
                    if (++$calls === 1) {
                        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $barrierName, 2)->arriveAndWait('w0', 5.0);
                    }

                    return SafetyVerdict::allow();
                });

                $res = $this->app->make(PostService::class)->publish(new PublishPostCommand(
                    key: $sharedKey,
                    surface: $surface,
                    body: 'Concurrent Same Key',
                    audience: AudienceSpec::platform(),
                ));

                return $res->toWire();
            },
            function () use ($surface, $sharedKey, $barrierName): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $calls = 0;
                $fakeChild->safety->using(static function () use ($barrierName, &$calls): SafetyVerdict {
                    if (++$calls === 1) {
                        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $barrierName, 2)->arriveAndWait('w1', 5.0);
                    }

                    return SafetyVerdict::allow();
                });

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

        $casBarrier = 'pg-cas-edit-'.Ids::uuid7();
        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $casBarrier, 2)->reset();
        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $postId, $casBarrier): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $calls = 0;
                $fakeChild->safety->using(static function () use ($casBarrier, &$calls): SafetyVerdict {
                    if (++$calls === 1) {
                        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $casBarrier, 2)->arriveAndWait('cas-a', 5.0);
                    }

                    return SafetyVerdict::allow();
                });

                $res = $this->app->make(PostService::class)->edit(new EditPostCommand(
                    key: new CommandKey('cmd-pg-cas-edit-worker-a'),
                    surface: $surface,
                    postId: $postId,
                    expectedRevision: new Revision(1),
                    body: 'Edit from A',
                ));

                return $res->toWire();
            },
            function () use ($surface, $postId, $casBarrier): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $calls = 0;
                $fakeChild->safety->using(static function () use ($casBarrier, &$calls): SafetyVerdict {
                    if (++$calls === 1) {
                        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $casBarrier, 2)->arriveAndWait('cas-b', 5.0);
                    }

                    return SafetyVerdict::allow();
                });

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
        $preHideBarrier = 'pg-rvh-pre-'.Ids::uuid7();
        $postHideBarrier = 'pg-rvh-post-'.Ids::uuid7();
        $setupPdo = DB::connection('pgsql')->getPdo();
        new PostgresBarrier($setupPdo, $preHideBarrier, 2)->reset();
        new PostgresBarrier($setupPdo, $postHideBarrier, 2)->reset();
        DB::disconnect('pgsql');

        // Deterministically pause Bob after preflight TargetResolver::resolve(), hide the post from Alice, then release Bob into FOR UPDATE recheck!
        $outcomes = Concurrency::run([
            function () use ($surface, $postId, $preHideBarrier, $postHideBarrier): array {
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

                $barrierFence = new class($inner, $preHideBarrier, $postHideBarrier) implements TargetWriteFence
                {
                    private int $calls = 0;

                    public function __construct(
                        private readonly TargetWriteFence $inner,
                        private readonly string $preBarrier,
                        private readonly string $postBarrier,
                    ) {}

                    #[Override]
                    public function supports(HostEntityRef $target): bool
                    {
                        return $this->inner->supports($target);
                    }

                    #[Override]
                    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
                    {
                        $res = $this->inner->resolve($actor, $surface, $target);
                        if (DB::connection('pgsql')->transactionLevel() === 0 && ++$this->calls === 1) {
                            $pdo = DB::connection('pgsql')->getPdo();
                            new PostgresBarrier($pdo, $this->preBarrier, 2)->arriveAndWait('bob', 5.0);
                            new PostgresBarrier($pdo, $this->postBarrier, 2)->arriveAndWait('bob', 5.0);
                        }

                        return $res;
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
                    key: new CommandKey('cmd-pg-rvh-react-bob1'),
                    surface: $surface,
                    target: new HostEntityRef('post', $postId),
                    reactionType: 'like',
                ));

                return $res->toWire();
            },
            function () use ($surface, $postId, $preHideBarrier, $postHideBarrier): array {
                DB::purge('pgsql');
                $pdo = DB::connection('pgsql')->getPdo();
                new PostgresBarrier($pdo, $preHideBarrier, 2)->arriveAndWait('alice', 5.0);

                $res = $this->app->make(PostService::class)->transition(new TransitionPostStateCommand(
                    key: new CommandKey('cmd-pg-rvh-hide-alice'),
                    surface: $surface,
                    postId: $postId,
                    expectedRevision: new Revision(1),
                    targetState: ContentState::Hidden,
                    reasonCode: 'mod.hide',
                ));

                new PostgresBarrier($pdo, $postHideBarrier, 2)->arriveAndWait('alice', 5.0);

                return $res->toWire();
            },
        ]);

        self::assertTrue($outcomes[1]->succeeded, $outcomes[1]->errorMessage ?? 'hide failed');
        self::assertFalse($outcomes[0]->succeeded, 'Bob reaction must deterministically fail closed after post hide.');
        self::assertSame(OperationException::class, $outcomes[0]->errorClass);
        self::assertSame(0, DB::connection('pgsql')->table('sk_reactions')->where('target_key', $postId)->count());

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

        $ensureBarrier = 'pg-ensure-race-'.Ids::uuid7();
        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $ensureBarrier, 2)->reset();
        DB::disconnect('pgsql');

        // #19: Concurrent ensureInstance on absent row meets at pre-TX barrier then enters savepoint race -> 1 surface created
        $ensureOutcomes = Concurrency::run([
            function () use ($panelId, $kindId, $host, $ensureBarrier): string {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $calls = 0;
                $fakeChild->surfacePolicy->admitUsing(static function () use ($ensureBarrier, &$calls): AudienceVerdict {
                    if (++$calls === 1) {
                        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $ensureBarrier, 2)->arriveAndWait('ensure-a', 5.0);
                    }

                    return AudienceVerdict::allow('surface.writable');
                });

                return $this->app->make(SurfaceService::class)
                    ->ensureInstance(new CommandKey('cmd-pg-ensure-race-a'), $panelId, $kindId, $host)
                    ->id;
            },
            function () use ($panelId, $kindId, $host, $ensureBarrier): string {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $calls = 0;
                $fakeChild->surfacePolicy->admitUsing(static function () use ($ensureBarrier, &$calls): AudienceVerdict {
                    if (++$calls === 1) {
                        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $ensureBarrier, 2)->arriveAndWait('ensure-b', 5.0);
                    }

                    return AudienceVerdict::allow('surface.writable');
                });

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

        // #14: Publish 4 posts, then run two PG outbox relay workers concurrently with a live consume barrier -> SKIP LOCKED delivers each event exactly once
        $posts = $this->app->make(PostService::class);
        for ($i = 1; $i <= 4; $i++) {
            self::assertTrue($posts->publish(new PublishPostCommand(
                key: new CommandKey("cmd-pg-skip-locked-post-{$i}"),
                surface: $surface,
                body: "Outbox worker item {$i}",
                audience: AudienceSpec::platform(),
            ))->isSuccess());
        }

        $skipLockedBarrier = 'pg-skip-locked-'.Ids::uuid7();
        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $skipLockedBarrier, 2)->reset();
        DB::disconnect('pgsql');

        $relayOutcomes = Concurrency::run([
            function () use ($panelId, $skipLockedBarrier): array {
                DB::purge('pgsql');
                $spy = new class('worker-a', $skipLockedBarrier) implements OutboxConsumer
                {
                    /** @var list<string> */
                    public array $ids = [];

                    private int $calls = 0;

                    public function __construct(
                        private readonly string $key,
                        private readonly string $barrierName,
                    ) {}

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
                        if (++$this->calls === 1) {
                            new PostgresBarrier(DB::connection('pgsql')->getPdo(), $this->barrierName, 2)->arriveAndWait('outbox-a', 5.0);
                        }

                        $this->ids[] = $event->id;
                    }
                };
                $this->app->instance(SpyOutboxConsumer::class, $spy);
                $this->app->tag([SpyOutboxConsumer::class], OutboxConsumer::class);
                $this->app->make(OutboxRelay::class)->relayPending($panelId, batchSize: 3, mode: ReplayMode::Normal);

                return $spy->ids;
            },
            function () use ($panelId, $skipLockedBarrier): array {
                DB::purge('pgsql');
                $spy = new class('worker-b', $skipLockedBarrier) implements OutboxConsumer
                {
                    /** @var list<string> */
                    public array $ids = [];

                    private int $calls = 0;

                    public function __construct(
                        private readonly string $key,
                        private readonly string $barrierName,
                    ) {}

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
                        if (++$this->calls === 1) {
                            new PostgresBarrier(DB::connection('pgsql')->getPdo(), $this->barrierName, 2)->arriveAndWait('outbox-b', 5.0);
                        }

                        $this->ids[] = $event->id;
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

        $rebuildBarrier = 'pg-rebuild-race-'.Ids::uuid7();
        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $rebuildBarrier, 2)->reset();
        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $postId, $rebuildBarrier): array {
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

                $barrierFence = new class($inner, $rebuildBarrier) implements TargetWriteFence
                {
                    private int $calls = 0;

                    public function __construct(
                        private readonly TargetWriteFence $inner,
                        private readonly string $barrierName,
                    ) {}

                    #[Override]
                    public function supports(HostEntityRef $target): bool
                    {
                        return $this->inner->supports($target);
                    }

                    #[Override]
                    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
                    {
                        $res = $this->inner->resolve($actor, $surface, $target);
                        if (DB::connection('pgsql')->transactionLevel() === 0 && ++$this->calls === 1) {
                            new PostgresBarrier(DB::connection('pgsql')->getPdo(), $this->barrierName, 2)->arriveAndWait('add-react', 5.0);
                        }

                        return $res;
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
                    key: new CommandKey('cmd-pg-rebuild-add-1'),
                    surface: $surface,
                    target: new HostEntityRef('post', $postId),
                    reactionType: 'like',
                ));

                return $res->toWire();
            },
            function () use ($surface, $rebuildBarrier): int {
                DB::purge('pgsql');
                new PostgresBarrier(DB::connection('pgsql')->getPdo(), $rebuildBarrier, 2)->arriveAndWait('rebuild', 5.0);
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

    public function test_parallel_normal_and_projection_outbox_workers_preserve_delivered_consumers_on_postgresql(): void
    {
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.pg'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'pg-outbox-ack-merge'), Incarnation::initial());
        $this->app->make(SurfaceService::class)->ensureInstance(new CommandKey('cmd-pg-ack-surf-1'), $surface->panel, $surface->kind, $surface->host);

        $drain = new SpyOutboxConsumer('pg.drain');
        $this->app->instance('test.pg.ack.drain', $drain);
        $this->app->tag(['test.pg.ack.drain'], OutboxConsumer::class);
        $relay = $this->app->make(OutboxRelay::class);
        $relay->relayPending($surface->panel, 50, ReplayMode::Normal);
        $relay->relayPending($surface->panel, 50, ReplayMode::ProjectionOnly);

        $pub = $this->app->make(PostService::class)->publish(new PublishPostCommand(
            key: new CommandKey('cmd-pg-ack-post-1'),
            surface: $surface,
            body: 'PG overlapping ACK merge',
            audience: AudienceSpec::platform(),
        ));
        $postId = (string) $pub->resource?->id;

        $claimedBarrier = 'pg-ack-claimed-'.Ids::uuid7();
        $mergeBarrier = 'pg-ack-merge-'.Ids::uuid7();
        $setupPdo = DB::connection('pgsql')->getPdo();
        new PostgresBarrier($setupPdo, $claimedBarrier, 2)->reset();
        new PostgresBarrier($setupPdo, $mergeBarrier, 2)->reset();
        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $claimedBarrier, $mergeBarrier): int {
                DB::purge('pgsql');
                $consumer = new readonly class($claimedBarrier, $mergeBarrier) implements OutboxConsumer
                {
                    public function __construct(
                        private string $claimedBarrier,
                        private string $mergeBarrier,
                    ) {}

                    #[Override]
                    public function consumerKey(): string
                    {
                        return 'pg.normal.worker';
                    }

                    #[Override]
                    public function supports(string $eventType): bool
                    {
                        return $eventType === 'sockit.posts.published';
                    }

                    #[Override]
                    public function consume(OutboxEventRecord $event, ReplayMode $mode): void
                    {
                        $pdo = DB::connection('pgsql')->getPdo();
                        new PostgresBarrier($pdo, $this->claimedBarrier, 2)->arriveAndWait('normal-claimed', 5.0);
                        new PostgresBarrier($pdo, $this->mergeBarrier, 2)->arriveAndWait('normal-merge', 5.0);
                    }
                };
                $this->app->instance(SpyOutboxConsumer::class, $consumer);
                $this->app->tag([SpyOutboxConsumer::class], OutboxConsumer::class);

                return $this->app->make(OutboxRelay::class)->relayPending($surface->panel, 10, ReplayMode::Normal);
            },
            function () use ($surface, $claimedBarrier, $mergeBarrier): int {
                DB::purge('pgsql');
                new PostgresBarrier(DB::connection('pgsql')->getPdo(), $claimedBarrier, 2)->arriveAndWait('projection-ready', 5.0);

                $consumer = new readonly class($mergeBarrier) implements OutboxConsumer
                {
                    public function __construct(private string $mergeBarrier) {}

                    #[Override]
                    public function consumerKey(): string
                    {
                        return 'pg.projection.worker';
                    }

                    #[Override]
                    public function supports(string $eventType): bool
                    {
                        return $eventType === 'sockit.posts.published';
                    }

                    #[Override]
                    public function consume(OutboxEventRecord $event, ReplayMode $mode): void
                    {
                        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $this->mergeBarrier, 2)->arriveAndWait('projection-merge', 5.0);
                    }
                };
                $this->app->instance(SpyOutboxConsumer::class, $consumer);
                $this->app->tag([SpyOutboxConsumer::class], OutboxConsumer::class);

                return $this->app->make(OutboxRelay::class)->relayPending($surface->panel, 10, ReplayMode::ProjectionOnly);
            },
        ]);

        self::assertTrue($outcomes[0]->succeeded, $outcomes[0]->errorMessage ?? 'normal outbox failed');
        self::assertTrue($outcomes[1]->succeeded, $outcomes[1]->errorMessage ?? 'projection outbox failed');
        self::assertSame(1, $outcomes[0]->value);
        self::assertSame(1, $outcomes[1]->value);

        $eventRow = DB::connection('pgsql')->table('sk_outbox_events')->where('aggregate_id', $postId)->first();
        $delivered = json_decode((string) $eventRow->delivered_consumers, true, 16, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('pg.normal.worker', $delivered['normal'] ?? []);
        self::assertArrayHasKey('pg.projection.worker', $delivered['projection'] ?? []);
    }

    public function test_parallel_group_uuid_vs_slug_locking_canonical_order_prevents_postgresql_deadlock(): void
    {
        $fake = $this->installFakeSocKit();
        $fake->consent->allowAll();
        $fake->actors->actAsPerson('alice');

        $surface = new SurfaceRef(new PanelId('sockit.pg'), new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'pg-canon-group-lock'), Incarnation::initial());
        $groups = $this->app->make(GroupService::class);
        $posts = $this->app->make(PostService::class);

        // Ensure first UUID < second UUID while slugs have inverse alphabetical order ('slug-z' > 'a-slug-...')
        $gFirst = (string) $groups->create(new CreateGroupCommand(new CommandKey('cmd-pgl-create-grp-1'), $surface, 'slug-z', 'Group Z', GroupVisibility::Private, joinPolicy: GroupJoinPolicy::Auto))->resource?->id;
        do {
            $slugCandidate = 'a-slug-'.bin2hex(random_bytes(2));
            $gHigher = (string) $groups->create(new CreateGroupCommand(new CommandKey('cmd-pgl-ga-'.Ids::uuid7()), $surface, $slugCandidate, 'Group A', GroupVisibility::Private, joinPolicy: GroupJoinPolicy::Auto))->resource?->id;
        } while ($gHigher <= $gFirst);

        $alice = new ActorRef('user', 'alice');
        $bob = new ActorRef('user', 'bob');
        $carol = new ActorRef('user', 'carol');
        $groups->addMember(new AddGroupMemberCommand(new CommandKey('cmd-pgl-add-alice-01'), $surface, $gFirst, new Revision(1), $alice, GroupMemberRole::Owner));
        $groups->addMember(new AddGroupMemberCommand(new CommandKey('cmd-pgl-add-alice-02'), $surface, $gHigher, new Revision(1), $alice, GroupMemberRole::Owner));
        $groups->addMember(new AddGroupMemberCommand(new CommandKey('cmd-pgl-add-bob-001'), $surface, $gFirst, new Revision(2), $bob));
        $groups->addMember(new AddGroupMemberCommand(new CommandKey('cmd-pgl-add-bob-002'), $surface, $gHigher, new Revision(2), $bob));
        $groups->addMember(new AddGroupMemberCommand(new CommandKey('cmd-pgl-add-carol-01'), $surface, $gFirst, new Revision(3), $carol));
        $groups->addMember(new AddGroupMemberCommand(new CommandKey('cmd-pgl-add-carol-02'), $surface, $gHigher, new Revision(3), $carol));

        $postByUuid = (string) $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-pgl-post-uuid'),
            surface: $surface,
            body: 'UUID ordered audience',
            audience: AudienceSpec::allOf(AudienceSpec::group(new GroupRef($gFirst)), AudienceSpec::group(new GroupRef($gHigher))),
        ))->resource?->id;

        $postBySlug = (string) $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-pgl-post-slug'),
            surface: $surface,
            body: 'Slug reverse-alphabet audience',
            audience: AudienceSpec::allOf(AudienceSpec::group(new GroupRef($slugCandidate)), AudienceSpec::group(new GroupRef('slug-z'))),
        ))->resource?->id;

        $lockRaceBarrier = 'pg-canon-lock-'.Ids::uuid7();
        new PostgresBarrier(DB::connection('pgsql')->getPdo(), $lockRaceBarrier, 2)->reset();
        DB::disconnect('pgsql');

        $outcomes = Concurrency::run([
            function () use ($surface, $postByUuid, $lockRaceBarrier): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $fakeChild->actors->actAsPerson('bob');

                $inner = null;
                foreach ($this->app->make(Extensions::class)->resolve(TargetResolver::class) as $candidate) {
                    if ($candidate->supports(new HostEntityRef('post', $postByUuid))) {
                        $inner = $candidate;

                        break;
                    }
                }
                if (! $inner instanceof TargetWriteFence) {
                    throw new LogicException('Expected TargetWriteFence.');
                }

                $fence = new class($inner, $lockRaceBarrier) implements TargetWriteFence
                {
                    private int $calls = 0;

                    public function __construct(private readonly TargetWriteFence $inner, private readonly string $barrierName) {}

                    #[Override]
                    public function supports(HostEntityRef $target): bool
                    {
                        return $this->inner->supports($target);
                    }

                    #[Override]
                    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
                    {
                        $res = $this->inner->resolve($actor, $surface, $target);
                        if (DB::connection('pgsql')->transactionLevel() === 0 && ++$this->calls === 1) {
                            new PostgresBarrier(DB::connection('pgsql')->getPdo(), $this->barrierName, 2)->arriveAndWait('uuid-worker', 5.0);
                        }

                        return $res;
                    }

                    #[Override]
                    public function recheck(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target, TargetResolution $prechecked): AuthorizationDecision
                    {
                        return $this->inner->recheck($actor, $surface, $target, $prechecked);
                    }
                };
                $this->app->instance(TargetResolver::class, $fence);

                return $this->app->make(ReactionService::class)->add(new AddReactionCommand(
                    key: new CommandKey('cmd-pgl-react-bob'),
                    surface: $surface,
                    target: new HostEntityRef('post', $postByUuid),
                    reactionType: 'like',
                ))->toWire();
            },
            function () use ($surface, $postBySlug, $lockRaceBarrier): array {
                DB::purge('pgsql');
                $fakeChild = $this->app->make(SocKitFake::class);
                $fakeChild->actors->actAsPerson('carol');

                $inner = null;
                foreach ($this->app->make(Extensions::class)->resolve(TargetResolver::class) as $candidate) {
                    if ($candidate->supports(new HostEntityRef('post', $postBySlug))) {
                        $inner = $candidate;

                        break;
                    }
                }
                if (! $inner instanceof TargetWriteFence) {
                    throw new LogicException('Expected TargetWriteFence.');
                }

                $fence = new class($inner, $lockRaceBarrier) implements TargetWriteFence
                {
                    private int $calls = 0;

                    public function __construct(private readonly TargetWriteFence $inner, private readonly string $barrierName) {}

                    #[Override]
                    public function supports(HostEntityRef $target): bool
                    {
                        return $this->inner->supports($target);
                    }

                    #[Override]
                    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
                    {
                        $res = $this->inner->resolve($actor, $surface, $target);
                        if (DB::connection('pgsql')->transactionLevel() === 0 && ++$this->calls === 1) {
                            new PostgresBarrier(DB::connection('pgsql')->getPdo(), $this->barrierName, 2)->arriveAndWait('slug-worker', 5.0);
                        }

                        return $res;
                    }

                    #[Override]
                    public function recheck(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target, TargetResolution $prechecked): AuthorizationDecision
                    {
                        return $this->inner->recheck($actor, $surface, $target, $prechecked);
                    }
                };
                $this->app->instance(TargetResolver::class, $fence);

                return $this->app->make(ReactionService::class)->add(new AddReactionCommand(
                    key: new CommandKey('cmd-pgl-react-carol'),
                    surface: $surface,
                    target: new HostEntityRef('post', $postBySlug),
                    reactionType: 'like',
                ))->toWire();
            },
        ]);

        self::assertTrue($outcomes[0]->succeeded, $outcomes[0]->errorMessage ?? 'UUID-order worker deadlocked/failed');
        self::assertTrue($outcomes[1]->succeeded, $outcomes[1]->errorMessage ?? 'Slug-order worker deadlocked/failed');

        $reactions = $this->app->make(ReactionService::class);
        $fake->actors->actAsPerson('bob');
        self::assertSame(1, $reactions->counts($surface, new HostEntityRef('post', $postByUuid))->total);
        self::assertSame(1, $reactions->counts($surface, new HostEntityRef('post', $postBySlug))->total);
    }
}
