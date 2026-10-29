<?php

declare(strict_types=1);

namespace SocKit\Testing;

use SocKit\Actors\Data\ActorContext;
use SocKit\Attributes\Api;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Surfaces\Data\SurfaceRef;

#[Api(since: '1.0')]
final class FakeSurfaceAudiencePolicy implements SurfaceAudiencePolicy
{
    /**
     * @var array<string, AudienceVerdict> */
    private array $admissions = [];

    /**
     * @var array<string, AuthorizationDecision> */
    private array $boundaries = [];

    private ?\Throwable $failure = null;

    public function allow(ActorContext $viewer, SurfaceRef $surface, string $reason = 'audience.allowed.platform'): self
    {
        return $this->admission(viewer: $viewer, surface: $surface, verdict: AudienceVerdict::allow($reason));
    }

    public function allowReadOnly(ActorContext $viewer, SurfaceRef $surface, string $reason = 'audience.allowed.observer'): self
    {
        return $this->admission(viewer: $viewer, surface: $surface, verdict: AudienceVerdict::allowReadOnly($reason));
    }

    public function deny(ActorContext $viewer, SurfaceRef $surface, string $reason = 'audience.unknown'): self
    {
        return $this->admission(viewer: $viewer, surface: $surface, verdict: AudienceVerdict::deny($reason));
    }

    public function containment(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed, AuthorizationDecision $decision): self
    {
        $this->boundaries[$this->key(actor: $actor, surface: $surface).serialize($proposed->toWire())] = $decision;

        return $this;
    }

    public function failWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->admissions[$this->key(actor: $viewer, surface: $surface)] ?? AudienceVerdict::deny('audience.unknown');
    }

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->boundaries[$this->key(actor: $actor, surface: $surface).serialize($proposed->toWire())] ?? AuthorizationDecision::deny('fake.not_configured');
    }

    private function key(ActorContext $actor, SurfaceRef $surface): string
    {
        return serialize([$actor->installation->toWire(), $actor->actor?->toWire(), $actor->kind->value, $surface->toWire()]);
    }

    private function admission(ActorContext $viewer, SurfaceRef $surface, AudienceVerdict $verdict): self
    {
        $this->admissions[$this->key(actor: $viewer, surface: $surface)] = $verdict;

        return $this;
    }
}
