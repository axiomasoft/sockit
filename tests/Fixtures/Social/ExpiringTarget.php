<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Operations\Data\OperationHandle;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Contracts\TargetWriteFence;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Tests\Fixtures\Runtime\EnvelopeTarget;

/** The real local host fence is unchanged; only sampled verdict deadlines vary deterministically. */
final class ExpiringTarget implements SurfaceAudiencePolicy, TargetResolver, TargetWriteFence
{
    public \DateTimeImmutable $expiresAt;

    /** @var array<string, list<string>> */
    public array $expiries = ['host' => [], 'admission' => []];

    public function __construct(private readonly EnvelopeTarget $target, private readonly SurfaceAudiencePolicy $admission, private readonly string $refresh)
    {
        $this->expiresAt = new \DateTimeImmutable('+1 hour');
    }

    public static function install(PostReactionScenario $f, string $refresh): self
    {
        $s = $f->s;
        $adapter = new self(target: $s->target, admission: $s->fake->surfaces, refresh: $refresh);
        $s->app->instance(TargetResolver::class, $adapter);
        $s->app->instance(SurfaceAudiencePolicy::class, $adapter);
        $s->app->instance(AudienceGate::class, new AudienceGate(resolver: $s->app->make(AudienceResolver::class), surfacePolicy: $adapter));

        return $adapter;
    }

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $base = $this->target->resolve(actor: $actor, surface: $surface, target: $target);
        $expiry = $this->expiry('host');

        return new TargetResolution(surface: $base->surface, target: $base->target, installation: $base->installation,
            read: $this->verdict($base->read, $expiry), write: $this->verdict($base->write, $expiry), lifecycle: $base->lifecycle,
            privacyRevision: $base->privacyRevision, generation: $base->generation);
    }

    public function recheck(OperationHandle $handle, TargetResolution $resolved): AuthorizationDecision
    {
        return $this->target->recheck(handle: $handle, resolved: $resolved);
    }

    public function admit(ActorContext $viewer, SurfaceRef $surface): AudienceVerdict
    {
        return $this->verdict($this->admission->admit(viewer: $viewer, surface: $surface), $this->expiry('admission'));
    }

    public function contains(ActorContext $actor, SurfaceRef $surface, AudienceSpec $proposed): AuthorizationDecision
    {
        return $this->admission->contains(actor: $actor, surface: $surface, proposed: $proposed);
    }

    private function expiry(string $stage): \DateTimeImmutable
    {
        // Renewed deadlines stay earlier than the other stage's fixed TTL, so intersection cannot mask them.
        $seconds = $this->refresh === $stage || $this->refresh === 'both' ? count($this->expiries[$stage]) + 1 - 1800 : 0;
        $expiry = $this->expiresAt->modify('+'.$seconds.' seconds');
        $this->expiries[$stage][] = $expiry->format('Y-m-d\TH:i:s.uP');

        return $expiry;
    }

    private function verdict(AudienceVerdict $base, \DateTimeImmutable $expiry): AudienceVerdict
    {
        return new AudienceVerdict(decision: $base->decision, reason: $base->reason, policyVersion: $base->policyVersion, expiresAt: $expiry);
    }
}
