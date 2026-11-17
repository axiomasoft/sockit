<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Integration;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Override;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use PHPUnit\Framework\Attributes\Group;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\SingleInstallationTenantResolver;
use SocKit\Audience\AllowAuthenticatedPersonAuthorizer;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Core\Tests\Fixtures\HostProductionActorResolver;
use SocKit\Core\Tests\Fixtures\HostProductionContourResolver;
use SocKit\Core\Tests\Fixtures\HostProductionSurfacePolicy;
use SocKit\Core\Tests\Fixtures\PanelPgSchemaAlphaProvider;
use SocKit\Core\Tests\Fixtures\PanelPgSchemaBetaProvider;
use SocKit\Core\Tests\Fixtures\PgSchemaAlphaSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PgSchemaBetaSurfaceProvider;
use SocKit\Diagnostics\SockitDoctorCheck;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Groups\Enums\GroupVisibility;
use SocKit\Modules\Groups\Internal\DatabaseGroupMembershipResolver;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\EditPostCommand;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Operations\Contracts\OutboxConsumer;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Operations\Data\OutboxEventRecord;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testkit\SocKitTestCase;

/**
 * Verifies real production SPI wiring (zero SocKitFake / Fake* doubles) and PostgreSQL multi-schema panel isolation.
 */
#[Group('pg')]
final class ProductionWiringAndMultiSchemaPgTest extends SocKitTestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make(ConfigRepository::class);
        $config->set('database.default', 'pgsql');
        $config->set('sockit.storage.connection', 'pgsql');
        $config->set('sockit.resolvers.tenant', SingleInstallationTenantResolver::class);
        $config->set('sockit.resolvers.authorizer', AllowAuthenticatedPersonAuthorizer::class);
        $config->set('sockit.resolvers.actor', HostProductionActorResolver::class);
        $config->set('sockit.resolvers.surface_policy', HostProductionSurfacePolicy::class);
        $config->set('sockit.resolvers.contour', HostProductionContourResolver::class);

        $app->scoped(HostProductionActorResolver::class);
        $app->scoped(HostProductionContourResolver::class);
    }

    /** @return list<class-string> */
    protected function sockitPanelProviders(): array
    {
        return [PanelPgSchemaAlphaProvider::class, PanelPgSchemaBetaProvider::class];
    }

    /** @return list<class-string> */
    protected function sockitSurfaceProviders(): array
    {
        return [PgSchemaAlphaSurfaceProvider::class, PgSchemaBetaSurfaceProvider::class];
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

    public function test_production_wiring_and_multi_schema_prefix_table_map_isolation_on_postgresql(): void
    {
        self::assertInstanceOf(DatabaseGroupMembershipResolver::class, $this->app->make(GroupMembershipResolver::class));

        $alphaTableCount = DB::connection('pgsql')->selectOne(
            "SELECT count(*) AS cnt FROM information_schema.tables WHERE table_schema = 'tenant_alpha' AND table_name = 'wall_posts'",
        );
        $betaTableCount = DB::connection('pgsql')->selectOne(
            "SELECT count(*) AS cnt FROM information_schema.tables WHERE table_schema = 'tenant_beta' AND table_name = 'wall_posts'",
        );
        self::assertSame(1, (int) $alphaTableCount->cnt);
        self::assertSame(1, (int) $betaTableCount->cnt);

        // Doctor check probes schema-qualified + mapped tables accurately
        $checks = $this->app->make(SockitDoctorCheck::class)->run(new DoctorContext(probeStorage: true));
        self::assertCount(1, $checks);
        self::assertSame(CheckStatus::Ok, $checks[0]->status);
        self::assertSame('sockit.doctor.healthy', $checks[0]->code);

        $actorHost = $this->app->make(HostProductionActorResolver::class);
        $contourHost = $this->app->make(HostProductionContourResolver::class);
        $posts = $this->app->make(PostService::class);
        $groups = $this->app->make(GroupService::class);
        $reactions = $this->app->make(ReactionService::class);
        $relay = $this->app->make(OutboxRelay::class);

        $consumer = new class implements OutboxConsumer
        {
            /** @var list<array{type: string, mode: ReplayMode}> */
            public array $events = [];

            #[Override]
            public function consumerKey(): string
            {
                return 'pg.prod.consumer';
            }

            #[Override]
            public function supports(string $eventType): bool
            {
                return str_starts_with($eventType, 'sockit.');
            }

            #[Override]
            public function consume(OutboxEventRecord $event, ReplayMode $mode): void
            {
                $this->events[] = ['type' => $event->eventType, 'mode' => $mode];
            }
        };
        $this->app->instance(OutboxConsumer::class.'.pg_prod', $consumer);
        $this->app->tag([OutboxConsumer::class.'.pg_prod'], OutboxConsumer::class);

        $alice = ActorContext::person(new ActorRef('user', 'alice'), new InstallationRef('default'));
        $bob = ActorContext::person(new ActorRef('user', 'bob'), new InstallationRef('default'));
        $anon = ActorContext::anonymous(new InstallationRef('default'));
        $team = new HostEntityRef('team', 'eng');
        $contourHost->grant($alice, 'team', $team);

        $alphaPanel = new PanelId('sockit.pg-alpha');
        $betaPanel = new PanelId('sockit.pg-beta');
        $alphaSurface = new SurfaceRef($alphaPanel, new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'open-board'), Incarnation::initial());
        $betaSurface = new SurfaceRef($betaPanel, new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'open-board'), Incarnation::initial());

        // Anonymous rejected by real AllowAuthenticatedPersonAuthorizer
        $actorHost->useContext($anon);
        try {
            $posts->publish(new PublishPostCommand(new CommandKey('cmd-pg-prod-anon-1'), $alphaSurface, 'Blocked'));
            self::fail('AllowAuthenticatedPersonAuthorizer must deny Anonymous writes.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame(SockitErrorCode::AuthorizationDenied, $e->problem());
        }

        // Alice creates 'staff' group in tenant_alpha and publishes contour & group posts
        $actorHost->useContext($alice);
        $alphaGroup = $groups->create(new CreateGroupCommand(
            key: new CommandKey('cmd-pg-prod-grp-alpha'),
            surface: $alphaSurface,
            slug: 'staff',
            title: 'Alpha Schema Staff',
            visibility: GroupVisibility::Private,
        ));
        self::assertTrue($alphaGroup->isSuccess());

        $alphaPost = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-pg-prod-post-alpha'),
            surface: $alphaSurface,
            body: 'Tenant alpha staff post',
            audience: AudienceSpec::group(new GroupRef('staff')),
        ));
        self::assertTrue($alphaPost->isSuccess());
        $alphaPostId = (string) $alphaPost->resource?->id;

        // Alice also creates a group & post in tenant_beta -> schema isolation keeps pa_* and pb_* separate!
        $bobGroup = $groups->create(new CreateGroupCommand(
            key: new CommandKey('cmd-pg-prod-grp-beta'),
            surface: $betaSurface,
            slug: 'staff',
            title: 'Beta Schema Staff',
            visibility: GroupVisibility::Private,
        ));
        self::assertTrue($bobGroup->isSuccess());

        // Switch to Bob: Bob creates nothing in alpha, cannot read Alice's alpha 'staff' post, nor publish on restricted-board
        $actorHost->useContext($bob);
        try {
            $posts->get($alphaSurface, $alphaPostId);
            self::fail('Non-member Bob cannot read Alice tenant_alpha group post.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        // Restricted board containment denied by real HostProductionSurfacePolicy
        $restrictedSurface = new SurfaceRef($betaPanel, new SurfaceKindId('sockit.wall'), new HostEntityRef('board', 'restricted-board'), Incarnation::initial());
        try {
            $posts->publish(new PublishPostCommand(
                key: new CommandKey('cmd-pg-prod-restricted-1'),
                surface: $restrictedSurface,
                body: 'Should fail containment',
                audience: AudienceSpec::platform(),
            ));
            self::fail('HostProductionSurfacePolicy containment must fail closed.');
        } catch (OperationException $e) {
            self::assertSame(SockitErrorCode::AudienceDenied, $e->problem());
        }

        // Alice edits alpha post & adds distinct reactions, verifying tenant_alpha schema storage + outbox relay
        $actorHost->useContext($alice);
        $editRes = $posts->edit(new EditPostCommand(
            key: new CommandKey('cmd-pg-prod-edit-alpha'),
            surface: $alphaSurface,
            postId: $alphaPostId,
            expectedRevision: new Revision(1),
            body: 'Tenant alpha updated body',
        ));
        self::assertTrue($editRes->isSuccess());

        self::assertTrue($reactions->add(new AddReactionCommand(new CommandKey('cmd-pg-prod-react-1'), $alphaSurface, new HostEntityRef('post', $alphaPostId), 'like'))->isSuccess());
        self::assertTrue($reactions->add(new AddReactionCommand(new CommandKey('cmd-pg-prod-react-2'), $alphaSurface, new HostEntityRef('post', $alphaPostId), 'love'))->isSuccess());

        self::assertSame(1, (int) DB::connection('pgsql')->table('tenant_alpha.wall_posts')->count());
        self::assertSame(0, (int) DB::connection('pgsql')->table('tenant_beta.wall_posts')->count());
        self::assertSame(2, (int) DB::connection('pgsql')->table('tenant_alpha.pa_reactions')->count());

        $relayed = $relay->relayPending($alphaPanel, 50, ReplayMode::Normal);
        self::assertGreaterThanOrEqual(4, $relayed);
        self::assertCount($relayed, $consumer->events);

        $this->artisan('sockit:reactions:rebuild', ['--panel' => 'sockit.pg-alpha'])->assertExitCode(0);
    }
}
