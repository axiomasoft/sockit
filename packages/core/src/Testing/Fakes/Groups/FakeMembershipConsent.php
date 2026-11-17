<?php

declare(strict_types=1);

namespace SocKit\Testing\Fakes\Groups;

use Closure;
use Override;
use Packman\Attributes\Api;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Modules\Groups\Contracts\MembershipConsent;
use SocKit\Modules\Groups\Data\ConsentDecision;
use SocKit\Modules\Groups\Data\MembershipActivation;
use SocKit\Support\Data\ActorRef;

/**
 * Configurable fail-closed test double for {@see MembershipConsent}.
 */
#[Api(since: '1.0')]
final class FakeMembershipConsent implements MembershipConsent
{
    private bool $defaultAllow = false;

    /** @var array<string, ConsentDecision> */
    private array $overrides = [];

    /** @var array<string, AuthorizationDecision> */
    private array $recheckOverrides = [];

    /** @var (Closure(MembershipActivation): ConsentDecision)|null */
    private ?Closure $assessHandler = null;

    /** @var (Closure(MembershipActivation, ConsentDecision): AuthorizationDecision)|null */
    private ?Closure $recheckHandler = null;

    public function allowAll(bool $allow = true): self
    {
        $this->defaultAllow = $allow;

        return $this;
    }

    public function allow(
        ActorRef $subject,
        ?string $groupId = null,
        string $generation = 'gen-1',
        string $reason = 'consent.allowed',
    ): self {
        $key = $this->key($subject, $groupId);
        $this->overrides[$key] = new ConsentDecision(AuthorizationDecision::allow($reason), $generation);

        return $this;
    }

    public function deny(
        ActorRef $subject,
        ?string $groupId = null,
        string $reason = 'consent.denied',
        string $generation = 'gen-denied',
    ): self {
        $key = $this->key($subject, $groupId);
        $this->overrides[$key] = new ConsentDecision(AuthorizationDecision::deny($reason), $generation);

        return $this;
    }

    public function unknown(
        ActorRef $subject,
        ?string $groupId = null,
        string $reason = 'consent.unknown',
        string $generation = 'gen-unknown',
    ): self {
        $key = $this->key($subject, $groupId);
        $this->overrides[$key] = new ConsentDecision(AuthorizationDecision::unknown($reason), $generation);

        return $this;
    }

    public function failRecheck(
        ActorRef $subject,
        ?string $groupId = null,
        ?AuthorizationDecision $decision = null,
    ): self {
        $this->recheckOverrides[$this->key($subject, $groupId)] = $decision ?? AuthorizationDecision::deny('consent.revoked');

        return $this;
    }

    /** @param Closure(MembershipActivation): ConsentDecision $handler */
    public function usingAssess(Closure $handler): self
    {
        $this->assessHandler = $handler;

        return $this;
    }

    /** @param Closure(MembershipActivation, ConsentDecision): AuthorizationDecision $handler */
    public function usingRecheck(Closure $handler): self
    {
        $this->recheckHandler = $handler;

        return $this;
    }

    public function reset(): self
    {
        $this->defaultAllow = false;
        $this->overrides = [];
        $this->recheckOverrides = [];
        $this->assessHandler = null;
        $this->recheckHandler = null;

        return $this;
    }

    #[Override]
    public function assess(MembershipActivation $activation): ConsentDecision
    {
        if ($this->assessHandler instanceof Closure) {
            return ($this->assessHandler)($activation);
        }

        $specific = $this->key($activation->subject, $activation->groupId);
        if (isset($this->overrides[$specific])) {
            return $this->overrides[$specific];
        }

        $wildcard = $this->key($activation->subject, null);
        if (isset($this->overrides[$wildcard])) {
            return $this->overrides[$wildcard];
        }

        if ($this->defaultAllow) {
            return new ConsentDecision(AuthorizationDecision::allow('consent.allowed'), 'gen-default');
        }

        return new ConsentDecision(AuthorizationDecision::deny('consent.required'), 'gen-required');
    }

    #[Override]
    public function recheck(MembershipActivation $activation, ConsentDecision $decision): AuthorizationDecision
    {
        if ($this->recheckHandler instanceof Closure) {
            return ($this->recheckHandler)($activation, $decision);
        }

        $specific = $this->key($activation->subject, $activation->groupId);
        if (isset($this->recheckOverrides[$specific])) {
            return $this->recheckOverrides[$specific];
        }

        $wildcard = $this->key($activation->subject, null);

        return $this->recheckOverrides[$wildcard] ?? $this->assess($activation)->decision;
    }

    private function key(ActorRef $subject, ?string $groupId): string
    {
        return $subject->toWire().'|'.($groupId ?? '*');
    }
}
