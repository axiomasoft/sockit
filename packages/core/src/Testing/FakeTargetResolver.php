<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetResolution;

/**
 * In-memory test double for host-owned {@see TargetResolver} entities (L0/standalone targets).
 */
#[Api(since: '1.0')]
final class FakeTargetResolver implements TargetResolver
{
    /** @var array<string, TargetResolution> */
    private array $targets = [];

    /** @var array<string, true> */
    private array $supportedTypes = [];

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
}
