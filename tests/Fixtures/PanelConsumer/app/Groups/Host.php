<?php

declare(strict_types=1);

namespace Acme\Groups;

use SocKit\Actors\Contracts\ActorContextResolver;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Audience\Contracts\ContourResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Enums\ContourMembership;
use SocKit\Audience\Enums\Scope;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Data\SurfaceRef;

final class Host implements ActorContextResolver, ContourResolver, SurfaceAudiencePolicy
{
    public string $id = '7';

    public string $installation = 'runtime-test';

    public bool $banned = false;

    public bool $unknown = false;

    public bool $consent = true;

    public bool $parentObserver = false;

    public function current(): ActorContext
    {
        return $this->reResolve(new ActorRef('user', $this->id), ContextProvenance::Direct);
    }

    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        return new ActorContext($actor, ActorKind::Person, new InstallationRef($this->installation), AgeBand::Adult, $provenance, generation: '1');
    }

    public function membership(ActorContext $actor, string $level, HostEntityRef $contour): ContourMembership
    {
        return ContourMembership::None;
    }

    public function enclosing(HostEntityRef $contour, string $level): ?HostEntityRef
    {
        return null;
    }

    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        if ($this->banned || ! $this->consent || $viewer->installation->id !== $this->installation || ! in_array($surface->panel->value, ['acme.article', 'acme.product'], true) || $viewer->actor?->type !== 'user') {
            return AudienceVerdict::deny('host.denied');
        }

        return $viewer->actor->id === 'parent' && $this->parentObserver ? AudienceVerdict::allowReadOnly('audience.allowed.observer') : AudienceVerdict::allow('audience.allowed.platform');
    }

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        if ($this->unknown) {
            return AuthorizationDecision::unknown('host.unknown');
        }
        $allowed = $proposed->scope === Scope::Direct || ($proposed->scope === Scope::Group && $surface->host->type === 'sockit.group' && $proposed->group?->value === $surface->host->value);

        return $allowed ? AuthorizationDecision::allow('host.exact_boundary') : AuthorizationDecision::deny('host.unproven');
    }
}
