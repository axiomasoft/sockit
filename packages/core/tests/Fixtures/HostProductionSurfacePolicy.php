<?php

declare(strict_types=1);

namespace SocKit\Core\Tests\Fixtures;

use Override;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Surfaces\Data\SurfaceRef;

final class HostProductionSurfacePolicy implements SurfaceAudiencePolicy
{
    #[Override]
    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        if ($viewer->kind === ActorKind::Anonymous) {
            return AudienceVerdict::allowReadOnly('host.anonymous_viewer');
        }

        return AudienceVerdict::allow('host.authenticated_member');
    }

    #[Override]
    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        if ($surface->host->value === 'restricted-board') {
            return AuthorizationDecision::deny('host.restricted_board');
        }

        return AuthorizationDecision::allow('host.contained');
    }
}
