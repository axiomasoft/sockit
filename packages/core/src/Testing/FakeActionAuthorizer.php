<?php

declare(strict_types=1);

namespace SocKit\Testing;

use SocKit\Attributes\Api;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;

#[Api(since: '1.0')]
final class FakeActionAuthorizer implements ActionAuthorizer
{
    /** @var array<string, AuthorizationDecision> */
    private array $decisions = [];

    public function allow(string $action): self
    {
        $this->decisions[$action] = AuthorizationDecision::allow('fake.allowed');

        return $this;
    }

    public function deny(string $action, string $reason): self
    {
        $this->decisions[$action] = AuthorizationDecision::deny($reason);

        return $this;
    }

    public function unknown(string $action): self
    {
        $this->decisions[$action] = AuthorizationDecision::unknown('fake.unknown');

        return $this;
    }

    public function decide(ActionRequest $request): AuthorizationDecision
    {
        return $this->decisions[$request->action] ?? AuthorizationDecision::deny('fake.not_configured');
    }
}
