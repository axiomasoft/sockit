<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Groups;

use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Tests\Fixtures\Runtime\EnvelopeAuthority;

final class MembershipAuthority implements ActionAuthorizer
{
    /** @var list<string> */
    public array $denied = [];

    public function __construct(private readonly EnvelopeAuthority $host) {}

    public function decide(ActionRequest $request): AuthorizationDecision
    {
        $decision = $this->host->decide($request);

        return in_array($request->action, $this->denied, true) ? AuthorizationDecision::deny('fixture.action_not_granted') : $decision;
    }
}
