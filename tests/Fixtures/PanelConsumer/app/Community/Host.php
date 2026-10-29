<?php

declare(strict_types=1);

namespace Acme\Community;

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

/** Mutable host facts, kept outside SocKit and resolved on each operation. */
final class Host implements ActorContextResolver, ContourResolver, SurfaceAudiencePolicy
{
    public string $id = 'teacher';

    public string $installation = 'runtime-test';

    /** @var array<string, bool> */
    public array $revoked = [];

    public function current(): ActorContext
    {
        return $this->context(new ActorRef($this->id === 'school' ? 'service' : 'user', $this->id), ContextProvenance::Direct);
    }

    public function reResolve(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        return $this->context($actor, $provenance);
    }

    private function context(ActorRef $actor, ContextProvenance $provenance): ActorContext
    {
        $service = $actor->type === 'service';

        return new ActorContext(actor: $actor, kind: $service ? ActorKind::Service : ActorKind::Person, installation: new InstallationRef($this->installation), ageBand: AgeBand::Adult, provenance: $provenance, onBehalfOf: $service ? new ActorRef('school', '7') : null, generation: '1');
    }

    public function membership(ActorContext $actor, string $level, HostEntityRef $contour): ContourMembership
    {
        if ($level !== 'class' || $contour->type !== 'class' || ! in_array($contour->value, ['42', '43'], true) || isset($this->revoked[$actor->actor->id ?? ''])) {
            return ContourMembership::None;
        }

        if ($contour->value === '43') {
            return $actor->actor?->id === 'teacher' ? ContourMembership::Live : ContourMembership::None;
        }

        return match ($actor->actor?->id) {
            'teacher', 'student', 'school' => ContourMembership::Live,
            'parent' => ContourMembership::ReadOnly,
            'graduate' => ContourMembership::Archived,
            default => ContourMembership::None,
        };
    }

    public function enclosing(HostEntityRef $contour, string $level): ?HostEntityRef
    {
        return null;
    }

    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        if ($viewer->installation->id !== 'runtime-test' || ! in_array($surface->panel->value, ['acme.community', 'acme.neighbor'], true) || $surface->kind->value !== 'acme.class-community' || $surface->host->type !== 'class' || $surface->host->value !== '42' || $surface->incarnation->value !== 1) {
            return AudienceVerdict::deny('audience.unknown');
        }

        return match ($this->membership($viewer, 'class', $surface->host)) {
            ContourMembership::Live => AudienceVerdict::allow('audience.allowed.contour_live'),
            ContourMembership::ReadOnly, ContourMembership::Archived => AudienceVerdict::allowReadOnly('audience.allowed.observer'),
            default => AudienceVerdict::deny('audience.denied.not_in_contour'),
        };
    }

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        $allowed = $proposed->scope === Scope::Contour && $proposed->contourLevel === 'class' && $proposed->contour?->type === 'class' && $proposed->contour->value === '42';
        if ($proposed->scope === Scope::Direct) {
            $allowed = array_all($proposed->actors, fn (ActorRef $ref): bool => in_array($ref->id, ['teacher', 'student', 'parent', 'school'], true));
        }

        return $allowed ? AuthorizationDecision::allow('host.contained') : AuthorizationDecision::deny('host.outside_class');
    }
}
