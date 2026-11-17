<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Packman\Context\Data\ScopeRef;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Core\Tests\Fixtures\AlphaWallSurfaceProvider;
use SocKit\Core\Tests\Fixtures\PanelAlphaProvider;
use SocKit\Errors\Enums\SockitErrorCode;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Groups\Data\CreateGroupCommand;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Posts\Data\PublishPostCommand;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Modules\Reactions\Data\AddReactionCommand;
use SocKit\Operations\Exceptions\OperationException;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\SockitPanelProvider;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Testing\Fakes\Actors\FakeActorContextResolver;
use SocKit\Testing\Fakes\Actors\FakeTenantResolver;
use SocKit\Testing\Fakes\Audience\FakeActionAuthorizer;
use SocKit\Testing\Fakes\Audience\FakeSurfaceAudiencePolicy;
use SocKit\Testkit\SocKitTestCase;

final class ProviderFailClosedAndScopedTest extends SocKitTestCase
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

    public function test_unconfigured_resolvers_boot_cleanly_and_fail_closed_across_all_services_and_panel_provider(): void
    {
        $this->migrateSockitSchema();

        self::assertNull(config('sockit.resolvers.tenant'));
        self::assertNull(config('sockit.resolvers.actor'));
        self::assertNull(config('sockit.resolvers.authorizer'));

        $panelId = new PanelId('sockit.alpha');
        $kindId = new SurfaceKindId('sockit.wall');
        $host = new HostEntityRef('board', '100');
        $surface = new SurfaceRef($panelId, $kindId, $host, Incarnation::initial());

        $panelProvider = $this->app->make(SockitPanelProvider::class);
        $panelContext = new PanelContext(new ScopeRef('inst-1'), 'user', 'alice');
        $panelReference = new PanelReference('sockit', 'alpha', null);

        $access = $panelProvider->access($panelContext, $panelReference);
        self::assertFalse($access->view);
        self::assertSame('provider_unavailable', $access->reason);

        $assertProviderUnavailable = static function (callable $call): void {
            try {
                $call();
                self::fail('Expected OperationException::providerUnavailable().');
            } catch (OperationException $e) {
                self::assertSame(SockitErrorCode::ProviderUnavailable, $e->problem());
            }
        };

        $assertProviderUnavailable(fn () => $this->app->make(SurfaceService::class)->ensureInstance(
            new CommandKey('cmd-fail-closed-surf-1'),
            $panelId,
            $kindId,
            $host,
        ));
        $assertProviderUnavailable(fn () => $this->app->make(PostService::class)->publish(new PublishPostCommand(
            key: new CommandKey('cmd-fail-closed-post-1'),
            surface: $surface,
            body: 'Must fail without providers',
        )));
        $assertProviderUnavailable(fn () => $this->app->make(GroupService::class)->create(new CreateGroupCommand(
            key: new CommandKey('cmd-fail-closed-group-1'),
            surface: $surface,
            slug: 'fail-closed',
            title: 'Fail Closed',
        )));
        $assertProviderUnavailable(fn () => $this->app->make(ReactionService::class)->add(new AddReactionCommand(
            key: new CommandKey('cmd-fail-closed-react-1'),
            surface: $surface,
            target: new HostEntityRef('post', '1'),
            reactionType: 'like',
        )));

        // Missing any single mandatory SPI (authorizer only) still fails closed
        $this->app->instance(TenantResolver::class, new FakeTenantResolver);
        $this->app->instance(ActorContextResolver::class, new FakeActorContextResolver);

        $accessWithoutAuthorizer = $panelProvider->access($panelContext, $panelReference);
        self::assertFalse($accessWithoutAuthorizer->view);
        self::assertSame('provider_unavailable', $accessWithoutAuthorizer->reason);

        $assertProviderUnavailable(fn () => $this->app->make(PostService::class)->publish(new PublishPostCommand(
            key: new CommandKey('cmd-fail-closed-post-2'),
            surface: $surface,
            body: 'Must fail without authorizer',
        )));
    }

    public function test_scoped_security_adapters_are_not_captured_as_stale_singletons_across_requests(): void
    {
        $this->migrateSockitSchema();

        $requestNumber = 0;

        $this->app->scoped(TenantResolver::class, static function () use (&$requestNumber): TenantResolver {
            return new FakeTenantResolver(new InstallationRef($requestNumber === 1 ? 'inst-a' : 'inst-b'));
        });
        $this->app->scoped(ActorContextResolver::class, static function () use (&$requestNumber): ActorContextResolver {
            $resolver = new FakeActorContextResolver;
            if ($requestNumber === 1) {
                $resolver->actAsPerson('alice', installation: 'inst-a');
            } else {
                $resolver->actAsPerson('bob', installation: 'inst-b');
            }

            return $resolver;
        });
        $this->app->scoped(ActionAuthorizer::class, static function () use (&$requestNumber): ActionAuthorizer {
            $auth = new FakeActionAuthorizer;
            if ($requestNumber === 2) {
                $auth->deny('sockit.posts.publish', 'request2.denied');
            }

            return $auth;
        });
        $this->app->scoped(SurfaceAudiencePolicy::class, static fn (): SurfaceAudiencePolicy => new FakeSurfaceAudiencePolicy);

        // Hold the exact same service & panel provider instances across simulated Octane requests
        $surfaces = $this->app->make(SurfaceService::class);
        $posts = $this->app->make(PostService::class);
        $panelProvider = $this->app->make(SockitPanelProvider::class);

        $panelId = new PanelId('sockit.alpha');
        $kindId = new SurfaceKindId('sockit.wall');
        $host = new HostEntityRef('board', 'scoped-1');
        $surface = new SurfaceRef($panelId, $kindId, $host, Incarnation::initial());

        // Request 1: inst-a / alice -> allowed
        $requestNumber = 1;
        $surfaces->ensureInstance(new CommandKey('cmd-scoped-surf-1'), $panelId, $kindId, $host);
        $pub1 = $posts->publish(new PublishPostCommand(
            key: new CommandKey('cmd-scoped-post-1'),
            surface: $surface,
            body: 'Request 1 post',
            audience: AudienceSpec::platform(),
        ));
        self::assertTrue($pub1->isSuccess());

        $access1 = $panelProvider->access(
            new PanelContext(new ScopeRef('inst-a'), 'user', 'alice'),
            new PanelReference('sockit', 'alpha', null),
        );
        self::assertTrue($access1->view);
        self::assertContains('sockit.posts.publish', $access1->actions);

        // Clear scoped instances cleanly and simulate Request 2 WITHOUT re-resolving $posts / $panelProvider
        $this->app->forgetScopedInstances();
        $requestNumber = 2;

        $access2 = $panelProvider->access(
            new PanelContext(new ScopeRef('inst-b'), 'user', 'bob'),
            new PanelReference('sockit', 'alpha', null),
        );
        self::assertTrue($access2->view);
        self::assertNotContains('sockit.posts.publish', $access2->actions);

        try {
            $posts->publish(new PublishPostCommand(
                key: new CommandKey('cmd-scoped-post-2'),
                surface: $surface,
                body: 'Request 2 should be denied',
                audience: AudienceSpec::platform(),
            ));
            self::fail('Expected AuthorizationDeniedException for Request 2.');
        } catch (AuthorizationDeniedException $e) {
            self::assertSame(SockitErrorCode::AuthorizationDenied, $e->problem());
        }

        // Verify Request 1 post receipt captured Request 1 installation & actor
        $receipt1 = DB::table('sk_command_receipts')->where('command_key', 'cmd-scoped-post-1')->first();
        self::assertNotNull($receipt1);
        self::assertStringStartsWith('inst-a/', (string) $receipt1->command_namespace);
    }
}
