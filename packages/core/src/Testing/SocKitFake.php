<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Api;
use SocKit\Actors\ActorContexts;
use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Contracts\TenantResolver;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Contracts\TargetResolver;

/** Explicit testing installation. No production resolver defaults are registered. */
#[Api(since: '1.0')]
final readonly class SocKitFake
{
    public FakeTenantResolver $tenants;

    public FakeActorContextResolver $actors;

    public FakeContourResolver $contours;

    public FakeGroupMembershipResolver $groups;

    public FakeActionAuthorizer $authorizer;

    public FakeAudienceResolver $audience;

    public FakeSurfaceAudiencePolicy $surfaces;

    public FakeTargetResolver $targets;

    private function __construct(InstallationRef $installation)
    {
        $this->tenants = new FakeTenantResolver($installation);
        $this->actors = new FakeActorContextResolver;
        $this->contours = new FakeContourResolver($installation);
        $this->groups = new FakeGroupMembershipResolver($installation);
        $this->authorizer = new FakeActionAuthorizer;
        $this->audience = new FakeAudienceResolver;
        $this->surfaces = new FakeSurfaceAudiencePolicy;
        $this->targets = new FakeTargetResolver;
    }

    public static function install(Application $app, ?InstallationRef $installation = null): self
    {
        $fake = new self($installation ?? new InstallationRef('test'));
        $app->instance(abstract: TargetResolver::class, instance: $fake->targets);
        $app->instance(abstract: TenantResolver::class, instance: $fake->tenants);
        $app->instance(abstract: ActorContextResolver::class, instance: $fake->actors);
        $app->instance(abstract: ContourResolver::class, instance: $fake->contours);
        $app->instance(abstract: GroupMembershipResolver::class, instance: $fake->groups);
        $app->instance(abstract: ActionAuthorizer::class, instance: $fake->authorizer);
        $app->instance(abstract: AudienceResolver::class, instance: $fake->audience);
        $app->instance(abstract: SurfaceAudiencePolicy::class, instance: $fake->surfaces);
        $app->instance(abstract: ActorContexts::class, instance: new ActorContexts(resolver: $fake->actors, tenants: $fake->tenants));
        $app->instance(abstract: AudienceGate::class, instance: new AudienceGate(resolver: $fake->audience, surfacePolicy: $fake->surfaces));

        return $fake;
    }
}
