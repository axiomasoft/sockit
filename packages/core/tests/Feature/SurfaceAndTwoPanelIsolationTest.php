<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Core\Tests\Fixtures\ConnAlphaSurfaceProvider;
use SocKit\Core\Tests\Fixtures\ConnBetaSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelConnAlphaProvider;
use SocKit\Core\Tests\Fixtures\PanelConnBetaProvider;
use SocKit\Database\StorageCollisionChecker;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Contracts\PanelDefinitions;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\PanelException;
use SocKit\Panels\SockitPanelBuilder;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Exceptions\SurfaceException;
use SocKit\Testkit\SocKitTestCase;

final class SurfaceAndTwoPanelIsolationTest extends SocKitTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make(ConfigRepository::class);
        $config->set('database.connections.conn_alpha', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $config->set('database.connections.conn_beta', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [PanelConnAlphaProvider::class, PanelConnBetaProvider::class];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [ConnAlphaSurfaceProvider::class, ConnBetaSurfaceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function sockitPrivacyFloor(): array
    {
        return [
            'allowed_scopes' => ['direct', 'platform'],
            'default_scope' => 'platform',
            'max_scope' => 'platform',
            'minor_default_scope' => 'direct',
            'minor_max_scope' => 'platform',
            'contour_levels' => [],
        ];
    }

    public function test_two_panels_with_identical_surface_kind_host_id_and_command_key_remain_isolated(): void
    {
        $this->migrateSockitSchema('conn_alpha');
        $this->migrateSockitSchema('conn_beta');
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $posts = $this->app->make(PostService::class);
        $sharedKind = new SurfaceKindId('shared.wall');
        $sharedHost = new HostEntityRef('board', '1');
        $sharedKey = new CommandKey('cmd-shared-panel-id-001');

        $alphaSurface = new SurfaceRef(new PanelId('app.alpha'), $sharedKind, $sharedHost, Incarnation::initial());
        $betaSurface = new SurfaceRef(new PanelId('app.beta'), $sharedKind, $sharedHost, Incarnation::initial());

        $alphaRes = $posts->publish(new PublishPostCommand($sharedKey, $alphaSurface, 'Alpha Secret Body', audience: AudienceSpec::platform()));
        $betaRes = $posts->publish(new PublishPostCommand($sharedKey, $betaSurface, 'Beta Secret Body', audience: AudienceSpec::platform()));

        $alphaPostId = $alphaRes->resource?->id ?? '';
        $betaPostId = $betaRes->resource?->id ?? '';
        self::assertNotSame($alphaPostId, $betaPostId);

        self::assertSame(1, DB::connection('conn_alpha')->table('sk_posts')->count());
        self::assertSame(1, DB::connection('conn_beta')->table('sk_posts')->count());
        self::assertSame('Alpha Secret Body', $posts->get($alphaSurface, $alphaPostId)->body);
        self::assertSame('Beta Secret Body', $posts->get($betaSurface, $betaPostId)->body);

        // Cross-panel read fails closed
        try {
            $posts->get($betaSurface, $alphaPostId);
            self::fail('Cross-panel read must not succeed.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::ContentHidden, $e->problem());
        }
    }

    public function test_surface_archive_blocks_writes_and_incarnation_bump_rejects_old_incarnation(): void
    {
        $this->migrateSockitSchema('conn_alpha');
        $this->migrateSockitSchema('conn_beta');
        $fake = $this->installFakeSocKit();
        $fake->actors->actAsPerson('alice');

        $surfaces = $this->app->make(SurfaceService::class);
        $posts = $this->app->make(PostService::class);

        $panelId = new PanelId('app.alpha');
        $kind = new SurfaceKindId('shared.wall');
        $host = new HostEntityRef('board', 'archive-1');

        $instance1 = $surfaces->ensureInstance(new CommandKey('cmd-surf-ensure-0001'), $panelId, $kind, $host);
        self::assertSame(1, $instance1->incarnation->value);

        // Idempotent ensureInstance
        $instance1Again = $surfaces->ensureInstance(new CommandKey('cmd-surf-ensure-0001'), $panelId, $kind, $host);
        self::assertSame($instance1->id, $instance1Again->id);

        $pubRes = $posts->publish(new PublishPostCommand(
            new CommandKey('cmd-surf-post-000001'),
            $instance1->ref(),
            'Pre-archive content',
            audience: AudienceSpec::platform(),
        ));
        $postId = $pubRes->resource?->id ?? '';

        // Archive blocks writes cleanly
        $archived = $surfaces->archive(new CommandKey('cmd-surf-arch-000001'), $instance1->ref());
        self::assertTrue($archived->isArchived());

        // archiveReaders=true on app.alpha allows reading archived post!
        self::assertSame('Pre-archive content', $posts->get($instance1->ref(), $postId)->body);

        try {
            $posts->publish(new PublishPostCommand(
                new CommandKey('cmd-surf-post-arch-001'),
                $instance1->ref(),
                'Write to archived',
                audience: AudienceSpec::platform(),
            ));
            self::fail('Archived surface must block writes.');
        } catch (SurfaceException $e) {
            self::assertSame(SockitErrorCode::SurfaceArchived, $e->problem());
        }

        // Start new incarnation -> incarnation 2 active; writes with incarnation 1 rejected!
        $instance2 = $surfaces->startNewIncarnation(new CommandKey('cmd-surf-reinc-00001'), $panelId, $kind, $host);
        self::assertSame(2, $instance2->incarnation->value);

        try {
            $posts->publish(new PublishPostCommand(
                new CommandKey('cmd-surf-post-oldinc-1'),
                $instance1->ref(),
                'Stale incarnation write',
                audience: AudienceSpec::platform(),
            ));
            self::fail('Stale incarnation must throw SurfaceIncarnationMismatch.');
        } catch (SurfaceException $e) {
            self::assertSame(SockitErrorCode::SurfaceIncarnationMismatch, $e->problem());
        }

        $res2 = $posts->publish(new PublishPostCommand(
            new CommandKey('cmd-surf-post-newinc-1'),
            $instance2->ref(),
            'New incarnation post',
            audience: AudienceSpec::platform(),
        ));
        self::assertTrue($res2->isSuccess());
    }

    public function test_storage_collision_checker_detects_alias_connections_to_identical_database(): void
    {
        $panels = $this->app->make(PanelDefinitions::class)->all();
        $checker = $this->app->make(StorageCollisionChecker::class);

        $aliasConnections = [
            'pg_primary' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'sockit_db', 'search_path' => 'public', 'password' => 'secret'],
            'pg_replica_alias' => ['driver' => 'pgsql', 'host' => ['127.0.0.1'], 'port' => '5432', 'database' => 'sockit_db', 'search_path' => 'public'],
        ];

        $p1 = new SockitPanelBuilder('p1')->id('sockit.one')->connection('pg_primary')->hostTypes('board')->materialize();
        $p2 = new SockitPanelBuilder('p2')->id('sockit.two')->connection('pg_replica_alias')->hostTypes('board')->materialize();

        try {
            $checker->assertNoCollisions(['sockit.one' => $p1, 'sockit.two' => $p2], $aliasConnections, 'pg_primary');
            self::fail('Expected PANEL_STORAGE_COLLISION across connection aliases.');
        } catch (PanelException $e) {
            self::assertSame(SockitErrorCode::ConfigurationInvalid, $e->problem());
            self::assertSame('PANEL_STORAGE_COLLISION', $e->detail()['diagnostic'] ?? null);
        }

        // Distinct prefix or distinct schema resolves collision cleanly
        $p2Distinct = new SockitPanelBuilder('p2')->id('sockit.two')->connection('pg_replica_alias')->prefix('sb_')->hostTypes('board')->materialize();
        $checker->assertNoCollisions(['sockit.one' => $p1, 'sockit.two' => $p2Distinct], $aliasConnections, 'pg_primary');
        self::assertCount(2, $panels);
    }
}
