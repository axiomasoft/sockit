<?php

declare(strict_types=1);

namespace Acme\Groups;

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Enums\Scope;
use SocKit\Surfaces\Data\SurfaceRef;

final class Policy implements SurfaceAudiencePolicy
{
    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        return new \Acme\Social\Policy()->admit($viewer, $surface);
    }

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        return $this->admit($actor, $surface)->canWrite() && $proposed->scope === Scope::Direct
            && $actor->actor !== null && count($proposed->actors) === 1 && $proposed->actors[0]->equals($actor->actor)
            ? AuthorizationDecision::allow('host.exact_executor') : AuthorizationDecision::deny('host.unproven_set');
    }
}
