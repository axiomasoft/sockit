<?php

declare(strict_types=1);

namespace Acme\Social;

use Acme\Runtime\Host;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Surfaces\Data\SurfaceRef;

final class Policy implements SurfaceAudiencePolicy
{
    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        $type = match ($surface->panel->value) {
            'acme.article' => 'article', 'acme.product' => 'product', default => null,
        };
        if ($viewer->installation->id !== 'runtime-test' || $viewer->actor?->type !== 'user' || $viewer->actor->id !== '7' || $type === null || $surface->host->type !== $type || $surface->host->value !== '42' || $surface->kind->value !== 'acme.social' || $surface->incarnation->value !== 1) {
            return AudienceVerdict::deny('host.unknown');
        }

        return AudienceVerdict::allow('audience.allowed.platform');
    }

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        return new Host()->contains($actor, $surface, $proposed);
    }
}
