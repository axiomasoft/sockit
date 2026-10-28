<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Runtime;

use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Support\Data\Revision;
use SocKit\Surfaces\Contracts\TargetResolver;
use SocKit\Surfaces\Data\SurfaceRef;
use SocKit\Surfaces\Data\TargetRef;
use SocKit\Surfaces\Data\TargetResolution;
use SocKit\Surfaces\Enums\TargetLifecycle;

/** Test-only host adapter. No social module or body reads. */
class TargetAdapter implements TargetResolver
{
    public ?TargetResolution $answer = null;

    public int $calls = 0;

    public bool $fail = false;

    public function resolve(ActorContext $actor, SurfaceRef $surface, TargetRef $target): TargetResolution
    {
        $this->calls++;
        if ($this->fail) {
            throw new \RuntimeException('Host unavailable');
        }

        return $this->answer ?? new TargetResolution(surface: $surface, target: $target, installation: $actor->installation,
            read: AudienceVerdict::allow('audience.allowed.platform'), write: AudienceVerdict::allow('audience.allowed.platform'),
            lifecycle: TargetLifecycle::Live, privacyRevision: new Revision(1), generation: 'current-1');
    }
}
