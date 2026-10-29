<?php

declare(strict_types=1);

namespace Acme;

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Surfaces\Data\SurfaceRef;

final class Host implements ContourResolver, GroupMembershipResolver, SurfaceAudiencePolicy
{
    public function membership(ActorContext $actor, string $level, HostEntityRef $contour): ContourMembership
    {
        return ContourMembership::None;
    }

    public function enclosing(HostEntityRef $contour, string $level): ?HostEntityRef
    {
        return null;
    }

    public function isMember(ActorContext $actor, GroupRef $group): bool
    {
        return false;
    }

    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        if ($viewer->installation->id !== 'runtime-test' || $viewer->actor?->type !== 'user' || $viewer->actor->id !== '7' || ! in_array($surface->panel->value, ['acme.a', 'acme.b'], true) || $surface->kind->value !== 'acme.runtime' || $surface->host->type !== 'article' || $surface->host->value !== '42' || $surface->incarnation->value !== 1) {
            return AudienceVerdict::deny('audience.unknown');
        }

        return AudienceVerdict::allow('audience.allowed.platform');
    }

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        return AuthorizationDecision::deny('host.no_publication');
    }
}
