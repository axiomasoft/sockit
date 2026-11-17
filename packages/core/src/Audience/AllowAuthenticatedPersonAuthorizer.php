<?php

declare(strict_types=1);

namespace SocKit\Audience;

use Override;
use Packman\Attributes\Api;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;

/**
 * Explicit opt-in authorizer allowing authenticated Person and Service actors while denying Anonymous/System actors.
 */
#[Api(since: '1.0')]
final readonly class AllowAuthenticatedPersonAuthorizer implements ActionAuthorizer
{
    #[Override]
    public function decide(ActionRequest $request): AuthorizationDecision
    {
        return match ($request->actor->kind) {
            ActorKind::Person, ActorKind::Service => AuthorizationDecision::allow('actor.authenticated'),
            ActorKind::Anonymous => AuthorizationDecision::deny('actor.anonymous'),
            ActorKind::System => AuthorizationDecision::deny('actor.system_not_permitted'),
        };
    }
}
