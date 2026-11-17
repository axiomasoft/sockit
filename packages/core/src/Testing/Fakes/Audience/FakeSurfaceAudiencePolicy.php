<?php

declare(strict_types=1);

namespace SocKit\Testing\Fakes\Audience;

use Closure;
use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Configurable fail-closed test double for {@see SurfaceAudiencePolicy}.
 */
#[Api(since: '1.0')]
final class FakeSurfaceAudiencePolicy implements SurfaceAudiencePolicy
{
    private ?AudienceVerdict $admitVerdict = null;

    private ?AuthorizationDecision $containsDecision = null;

    /** @var (Closure(ActorContext, SurfaceRef): AudienceVerdict)|null */
    private ?Closure $admitCallback = null;

    public function allowAll(): self
    {
        $this->admitVerdict = null;
        $this->containsDecision = null;
        $this->admitCallback = null;

        return $this;
    }

    public function denyAdmission(string $reason = 'surface.denied'): self
    {
        $this->admitVerdict = AudienceVerdict::deny($reason);

        return $this;
    }

    public function readOnlyAdmission(string $reason = 'surface.read_only'): self
    {
        $this->admitVerdict = AudienceVerdict::allowReadOnly($reason);

        return $this;
    }

    /** @param Closure(ActorContext, SurfaceRef): AudienceVerdict $callback */
    public function admitUsing(Closure $callback): self
    {
        $this->admitCallback = $callback;

        return $this;
    }

    public function denyContainment(string $reason = 'surface.outside_boundary'): self
    {
        $this->containsDecision = AuthorizationDecision::deny($reason);

        return $this;
    }

    public function unknownContainment(string $reason = 'surface.boundary_unproven'): self
    {
        $this->containsDecision = AuthorizationDecision::unknown($reason);

        return $this;
    }

    #[Override]
    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        if ($this->admitCallback instanceof Closure) {
            return ($this->admitCallback)($viewer, $surface);
        }

        if ($this->admitVerdict instanceof AudienceVerdict) {
            return $this->admitVerdict;
        }

        if ($viewer->kind === ActorKind::Anonymous) {
            return AudienceVerdict::allowReadOnly('surface.public_viewer');
        }

        return AudienceVerdict::allow('surface.admitted');
    }

    #[Override]
    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        if ($this->containsDecision instanceof AuthorizationDecision) {
            return $this->containsDecision;
        }

        return AuthorizationDecision::allow('surface.contained');
    }
}
