<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Integration;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Data\Compiler\ConstraintNames;
use Packman\Testkit\Concurrency\Process\Concurrency;
use Packman\Testkit\Data\Concurrency\ConcurrencyOutcome;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use SocKit\Audience\Data\AudienceSpec;
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
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\Revision;
use SocKit\Support\Ids;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
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

        // Atomic rollback leaves zero posts, zero receipts, zero outbox rows when TX callback throws
        $runner = $this->app->make(OperationEnvelopeRunner::class);
        $envelope = new CommandEnvelope(
            key: new CommandKey('cmd-pg-rollback-test-1'),
            namespace: new CommandNamespace(new InstallationRef('inst-1'), $surface->panel, 'sockit.posts', 'posts.publish'),
            digest: PayloadDigest::of('posts.publish', ['body' => 'boom']),
        );

        try {
            $runner->run(
                $envelope,
                $surface,
                'sockit.posts.publish',
                static function (): void {},
                static function ($actor, $connection): array {
                    $connection->table('sk_posts')->insert([
                        'id' => Ids::uuid7(),
                        'panel_id' => 'sockit.pg',
                        'surface_instance_id' => Ids::uuid7(),
                        'surface_incarnation' => 1,
                        'author_type' => 'user',
                        'author_id' => 'alice',
                        'state' => 'published',
                        'body_format' => 'plain',
                        'current_body' => 'Will rollback',
                        'revision' => 1,
                        'privacy_revision' => 1,
                        'audience_wire' => '{}',
                        'created_at' => gmdate('Y-m-d H:i:s.uP'),
                        'updated_at' => gmdate('Y-m-d H:i:s.uP'),
                    ]);

                    throw new RuntimeException('Simulated post-write failure before commit.');
                },
            );
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException) {
            self::assertSame(0, DB::connection('pgsql')->table('sk_posts')->count());
            self::assertSame(0, DB::connection('pgsql')->table('sk_command_receipts')->where('command_key', 'cmd-pg-rollback-test-1')->count());
            self::assertSame(0, DB::connection('pgsql')->table('sk_outbox_events')->count());
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
        self::assertSame(1, DB::connection('pgsql')->table('sk_outbox_events')->count());

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
}
