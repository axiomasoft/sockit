<?php

declare(strict_types=1);

namespace SocKit\Testing\Fakes\Audience;

use Closure;
use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;

/**
 * Configurable fail-closed test double for {@see ActionAuthorizer}.
 */
#[Api(since: '1.0')]
final class FakeActionAuthorizer implements ActionAuthorizer
{
    /** @var array<string, AuthorizationDecision> */
    private array $overrides = [];

    /** @var (Closure(ActionRequest): AuthorizationDecision)|null */
    private ?Closure $handler = null;

    public function deny(string $action, string $reason = 'test.denied'): self
    {
        $this->overrides[$action] = AuthorizationDecision::deny($reason);

        return $this;
    }

    public function allow(string $action, string $reason = 'test.allowed'): self
    {
        $this->overrides[$action] = AuthorizationDecision::allow($reason);

        return $this;
    }

    public function unknown(string $action, string $reason = 'test.unknown'): self
    {
        $this->overrides[$action] = AuthorizationDecision::unknown($reason);

        return $this;
    }

    /** @param Closure(ActionRequest): AuthorizationDecision $handler */
    public function using(Closure $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function reset(): self
    {
        $this->overrides = [];
        $this->handler = null;

        return $this;
    }

    #[Override]
    public function decide(ActionRequest $request): AuthorizationDecision
    {
        if ($this->handler instanceof Closure) {
            return ($this->handler)($request);
        }

        if (isset($this->overrides[$request->action])) {
            return $this->overrides[$request->action];
        }

        if ($request->actor->kind === ActorKind::Anonymous) {
            return AuthorizationDecision::deny('actor.anonymous');
        }

        if ($request->action === 'sockit.posts.view_hidden') {
            return AuthorizationDecision::deny('moderator.required');
        }

        return AuthorizationDecision::allow();
    }
}
