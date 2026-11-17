<?php

declare(strict_types=1);

namespace SocKit\Testing\Fakes\Surfaces;

use Closure;
use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;

/**
 * In-memory test double for host-owned {@see TargetWriteFence} entities defaulting fail-closed on write fences.
 */
#[Api(since: '1.0')]
final class FakeTargetResolver implements TargetWriteFence
{
    /** @var array<string, TargetResolution> */
    private array $targets = [];

    /** @var array<string, true> */
    private array $supportedTypes = [];

    private bool $localWriteFence = false;

    /** @var (Closure(ActorContext, SurfaceRef, HostEntityRef, TargetResolution): void)|null */
    private ?Closure $beforeRecheckCallback = null;

    public function withLocalWriteFence(bool $enabled = true): self
    {
        $this->localWriteFence = $enabled;

        return $this;
    }

    /** @param (Closure(ActorContext, SurfaceRef, HostEntityRef, TargetResolution): void)|null $callback */
    public function beforeRecheck(?Closure $callback): self
    {
        $this->beforeRecheckCallback = $callback;

        return $this;
    }

    public function register(
        HostEntityRef $target,
        ?ActorRef $owner = null,
        ?AudienceSpec $audience = null,
        ?Revision $privacyRevision = null,
    ): self {
        $this->supportedTypes[$target->type] = true;
        $this->targets[$target->toWire()] = TargetResolution::available($target, $owner, $audience, $privacyRevision);

        return $this;
    }

    public function hide(HostEntityRef $target, string $reason = 'target.hidden'): self
    {
        $this->supportedTypes[$target->type] = true;
        $this->targets[$target->toWire()] = TargetResolution::hidden($target, $reason);

        return $this;
    }

    public function remove(HostEntityRef $target, string $reason = 'target.not_found'): self
    {
        $this->supportedTypes[$target->type] = true;
        $this->targets[$target->toWire()] = TargetResolution::missing($target, $reason);

        return $this;
    }

    #[Override]
    public function supports(HostEntityRef $target): bool
    {
        return isset($this->supportedTypes[$target->type]);
    }

    #[Override]
    public function resolve(ActorContext $actor, SurfaceRef $surface, HostEntityRef $target): TargetResolution
    {
        return $this->targets[$target->toWire()] ?? TargetResolution::missing($target);
    }

    #[Override]
    public function recheck(
        ActorContext $actor,
        SurfaceRef $surface,
        HostEntityRef $target,
        TargetResolution $prechecked,
    ): AuthorizationDecision {
        if ($this->beforeRecheckCallback instanceof Closure) {
            ($this->beforeRecheckCallback)($actor, $surface, $target, $prechecked);
        }

        if (! $this->localWriteFence || ! $prechecked->privacyRevision instanceof Revision) {
            return AuthorizationDecision::unknown('reaction.target_fence_required');
        }

        $current = $this->resolve($actor, $surface, $target);

        if (! $current->isAccessible()) {
            return AuthorizationDecision::deny($current->reason ?? 'target.unavailable');
        }

        if (! $current->privacyRevision instanceof Revision) {
            return AuthorizationDecision::unknown('reaction.target_fence_required');
        }

        $preAudience = $prechecked->audience instanceof AudienceSpec ? CanonicalJson::encode($prechecked->audience->toWire()) : null;
        $curAudience = $current->audience instanceof AudienceSpec ? CanonicalJson::encode($current->audience->toWire()) : null;

        if ($current->privacyRevision->value !== $prechecked->privacyRevision->value || $preAudience !== $curAudience) {
            return AuthorizationDecision::deny('target.privacy_revision_stale');
        }

        return AuthorizationDecision::allow('target.fence_verified');
    }
}
