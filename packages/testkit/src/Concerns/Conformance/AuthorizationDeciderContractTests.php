<?php

declare(strict_types=1);

namespace SocKit\Testkit\Concerns\Conformance;

use Packman\Attributes\Api;
use PHPUnit\Framework\Assert;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Conformance test suite verifying fail-closed anonymous rejection on {@see ActionAuthorizer}.
 */
#[Api(since: '1.0')]
trait AuthorizationDeciderContractTests
{
    abstract protected function authorizerUnderTest(): ActionAuthorizer;

    public function test_authorizer_rejects_anonymous_mutation_requests(): void
    {
        $authorizer = $this->authorizerUnderTest();
        $surface = new SurfaceRef(
            panel: new PanelId('sockit.default'),
            kind: new SurfaceKindId('sockit.wall'),
            host: new HostEntityRef('user', '1'),
            incarnation: Incarnation::initial(),
        );

        $decision = $authorizer->decide(new ActionRequest(
            actor: ActorContext::anonymous(new InstallationRef('inst-1')),
            action: 'sockit.posts.publish',
            surface: $surface,
        ));

        Assert::assertFalse($decision->isAllowed());

        try {
            $decision->requireAllowed();
            Assert::fail('Expected AuthorizationDeniedException.');
        } catch (AuthorizationDeniedException $exception) {
            Assert::assertContains(
                $exception->problem()->value(),
                ['sockit.authorization_denied', 'sockit.authorization_unknown'],
            );
        }
    }
}
