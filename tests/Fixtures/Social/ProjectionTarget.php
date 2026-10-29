<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Social;

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;

final class ProjectionTarget implements TargetResolver
{
    public int $calls = 0;

    public function __construct(private readonly TargetResolver $delegate, private readonly string $mode) {}

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $this->calls++;
        if ($this->mode === 'error') {
            throw new \RuntimeException('resolver unavailable');
        }
        $resolved = $this->delegate->resolve(actor: $actor, surface: $surface, target: $target);
        $read = $resolved->read;
        if ($this->mode === 'expiry') {
            $read = new AudienceVerdict(decision: VerdictDecision::Allow, reason: 'audience.allowed.platform', expiresAt: new \DateTimeImmutable('-1 second'));
        }
        if ($this->mode === 'second-deny' && $this->calls > 1) {
            $read = AudienceVerdict::deny('audience.unknown');
        }

        return new TargetResolution(surface: $surface, target: $this->mode === 'binding' ? new TargetRef(host: $target->host, incarnation: $target->incarnation->next()) : $target,
            installation: $this->mode === 'installation' ? new InstallationRef('another') : $resolved->installation,
            read: $read, write: $resolved->write, lifecycle: $resolved->lifecycle, privacyRevision: $resolved->privacyRevision,
            generation: $this->mode === 'generation' ? (string) $this->calls : $resolved->generation);
    }
}
