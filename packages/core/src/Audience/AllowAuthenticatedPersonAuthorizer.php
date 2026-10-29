<?php

declare(strict_types=1);

namespace SocKit\Audience;

use SocKit\Actors\Enums\ActorKind;
use SocKit\Attributes\Api;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;

/** Opt in explicitly. Action, audience and content safety checks remain independent. */
#[Api(since: '1.0')]
final readonly class AllowAuthenticatedPersonAuthorizer implements ActionAuthorizer
{
    public function decide(ActionRequest $request): AuthorizationDecision
    {
        return in_array($request->actor->kind, [ActorKind::Person, ActorKind::Service], true)
            ? AuthorizationDecision::allow('authorization.authenticated_person')
            : AuthorizationDecision::deny('authorization.authentication_required');
    }
}
