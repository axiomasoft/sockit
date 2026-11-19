<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Default deny, exact installation/actor/generation/panel/surface/target keys. No write fence or SQL claims. */
#[Api(since: '1.0')]
final class FakeTargetResolver implements TargetResolver
{
    /** @var array<string, TargetResolution> */
    private array $resolutions = [];

    private ?\Throwable $failure = null;

    public function returns(ActorContext $actor, TargetResolution $resolved): self
    {
        $this->resolutions[$this->key(actor: $actor, surface: $resolved->surface, target: $resolved->target)] = $resolved;

        return $this;
    }

    public function failWith(\Throwable $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->resolutions[$this->key(actor: $actor, surface: $surface, target: $target)] ?? new TargetResolution(
            surface: $surface, target: $target, installation: $actor->installation, read: AudienceVerdict::deny('audience.unknown'),
            write: AudienceVerdict::deny('audience.unknown'), lifecycle: TargetLifecycle::Unknown, privacyRevision: new Revision(1), generation: 'fake.not_configured');
    }

    private function key(ActorContext $actor, SurfaceRef $surface, TargetRef $target): string
    {
        return serialize([$actor, $surface->toWire(), $target->toWire()]);
    }
}
