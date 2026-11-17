<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Modules\Groups\Contracts\GroupService;
use SocKit\Modules\Posts\Contracts\FeedService;
use SocKit\Modules\Posts\Contracts\PostService;
use SocKit\Modules\Reactions\Contracts\ReactionService;
use SocKit\Operations\Contracts\ContentSafetyChecker;
use SocKit\Operations\Contracts\OperationEnvelopeRunner;
use SocKit\Operations\Contracts\OutboxRelay;
use SocKit\Surfaces\Contracts\SurfaceService;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Testing\Fakes\Actors\FakeActorContextResolver;
use SocKit\Testing\Fakes\Actors\FakeTenantResolver;
use SocKit\Testing\Fakes\Audience\FakeActionAuthorizer;
use SocKit\Testing\Fakes\Audience\FakeContourResolver;
use SocKit\Testing\Fakes\Audience\FakeGroupMembershipResolver;
use SocKit\Testing\Fakes\Audience\FakeSurfaceAudiencePolicy;
use SocKit\Testing\Fakes\Operations\FakeContentSafetyChecker;
use SocKit\Testing\Fakes\Surfaces\FakeTargetResolver;

/**
 * Coordinates in-memory test doubles across container bindings for SocKit feature and unit suites.
 */
#[Api(since: '1.0')]
final readonly class SocKitFake
{
    public function __construct(
        public FakeTenantResolver $tenants = new FakeTenantResolver,
        public FakeActorContextResolver $actors = new FakeActorContextResolver,
        public FakeActionAuthorizer $authorizer = new FakeActionAuthorizer,
        public FakeSurfaceAudiencePolicy $surfacePolicy = new FakeSurfaceAudiencePolicy,
        public FakeContourResolver $contours = new FakeContourResolver,
        public FakeGroupMembershipResolver $groups = new FakeGroupMembershipResolver,
        public FakeTargetResolver $targets = new FakeTargetResolver,
        public FakeContentSafetyChecker $safety = new FakeContentSafetyChecker,
    ) {}

    public static function install(Container $container, bool $forceFakeGroups = false): self
    {
        $fake = new self;

        $container->instance(TenantResolver::class, $fake->tenants);
        $container->instance(ActorContextResolver::class, $fake->actors);
        $container->instance(ActionAuthorizer::class, $fake->authorizer);
        $container->instance(SurfaceAudiencePolicy::class, $fake->surfacePolicy);
        $container->instance(ContourResolver::class, $fake->contours);
        $container->instance(TargetResolver::class, $fake->targets);
        $container->instance(ContentSafetyChecker::class, $fake->safety);

        $groupsPluginActive = false;
        if (! $forceFakeGroups && $container->bound(PluginInspector::class)) {
            $inspector = $container->make(PluginInspector::class);
            foreach ($inspector->describe() as $plugin) {
                if ($plugin->id === 'axiomasoft.sockit.groups') {
                    $groupsPluginActive = true;

                    break;
                }
            }
        }

        if ($forceFakeGroups || ! $groupsPluginActive) {
            $container->instance(GroupMembershipResolver::class, $fake->groups);
        }

        if ($container instanceof IlluminateContainer) {
            foreach ([
                ActorContexts::class,
                AudienceResolver::class,
                AudienceGate::class,
                ReactionService::class,
                PostService::class,
                FeedService::class,
                GroupService::class,
                SurfaceService::class,
                OperationEnvelopeRunner::class,
                OutboxRelay::class,
            ] as $abstract) {
                $container->forgetInstance($abstract);
            }
        }

        $container->instance(self::class, $fake);

        return $fake;
    }
}
