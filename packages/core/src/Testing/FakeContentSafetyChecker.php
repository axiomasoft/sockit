<?php

declare(strict_types=1);

namespace SocKit\Testing;

use Closure;
use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Data\ActorContext;
use SocKit\Operations\Contracts\ContentSafetyChecker;
use SocKit\Operations\Data\SafetyVerdict;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Configurable test double for {@see ContentSafetyChecker} supporting allow, hold, and reject verdicts.
 */
#[Api(since: '1.0')]
final class FakeContentSafetyChecker implements ContentSafetyChecker
{
    /** @var (Closure(ActorContext, SurfaceRef, string, string, array<string, scalar|null>): SafetyVerdict)|null */
    private ?Closure $handler = null;

    private ?SafetyVerdict $forced = null;

    public function allowAll(): self
    {
        $this->forced = null;
        $this->handler = null;

        return $this;
    }

    public function holdAll(string $reason = 'safety.hold'): self
    {
        $this->forced = SafetyVerdict::hold($reason);

        return $this;
    }

    public function rejectAll(string $reason = 'safety.rejected'): self
    {
        $this->forced = SafetyVerdict::reject($reason);

        return $this;
    }

    /** @param Closure(ActorContext, SurfaceRef, string, string, array<string, scalar|null>): SafetyVerdict $handler */
    public function using(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    /** @param array<string, scalar|null> $metadata */
    #[Override]
    public function assess(ActorContext $actor, SurfaceRef $surface, string $contentType, string $body, array $metadata = []): SafetyVerdict
    {
        if ($this->handler instanceof Closure) {
            return ($this->handler)($actor, $surface, $contentType, $body, $metadata);
        }

        return $this->forced ?? SafetyVerdict::allow();
    }
}
