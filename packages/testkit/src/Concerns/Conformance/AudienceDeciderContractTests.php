<?php

declare(strict_types=1);

namespace SocKit\Testkit\Concerns\Conformance;

use Packman\Attributes\Api;
use PHPUnit\Framework\Assert;
use SocKit\Actors\Data\ActorContext;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Enums\VerdictDecision;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

/**
 * Conformance test suite verifying fail-closed installation isolation, anonymous restrictions,
 * and surface-level floor checks on any {@see AudienceResolver} implementation.
 */
#[Api(since: '1.0')]
trait AudienceDeciderContractTests
{
    abstract protected function audienceDeciderUnderTest(): AudienceResolver;

    public function test_audience_decider_denies_cross_installation_reads_and_writes(): void
    {
        $decider = $this->audienceDeciderUnderTest();
        $surface = new SurfaceRef(
            panel: new PanelId('sockit.default'),
            kind: new SurfaceKindId('sockit.feed'),
            host: new HostEntityRef('board', '1'),
            incarnation: Incarnation::initial(),
        );
        $subject = new AudienceSubject(
            key: 'item-1',
            spec: AudienceSpec::public(),
            author: new ActorRef('user', 'author'),
            surface: $surface,
            installation: new InstallationRef('inst-a'),
        );

        $otherTenantActor = ActorContext::person(new ActorRef('user', 'reader'), new InstallationRef('inst-b'));

        $readVerdict = $decider->decideRead($otherTenantActor, $subject);
        Assert::assertFalse($readVerdict->canRead());
        Assert::assertSame(VerdictDecision::Deny, $readVerdict->decision);

        $publishVerdict = $decider->decidePublish(
            $otherTenantActor,
            $subject,
            new PrivacyFloor([Scope::Public], Scope::Public, Scope::Public, Scope::Public, Scope::Public, []),
        );
        Assert::assertFalse($publishVerdict->canWrite());
    }

    public function test_audience_decider_never_allows_anonymous_publish(): void
    {
        $decider = $this->audienceDeciderUnderTest();
        $inst = new InstallationRef('inst-a');
        $surface = new SurfaceRef(
            panel: new PanelId('sockit.default'),
            kind: new SurfaceKindId('sockit.feed'),
            host: new HostEntityRef('board', '1'),
            incarnation: Incarnation::initial(),
        );
        $subject = new AudienceSubject(
            key: 'item-anon',
            spec: AudienceSpec::public(),
            author: null,
            surface: $surface,
            installation: $inst,
        );

        $verdict = $decider->decidePublish(
            ActorContext::anonymous($inst),
            $subject,
            new PrivacyFloor([Scope::Public], Scope::Public, Scope::Public, Scope::Public, Scope::Public, []),
        );

        Assert::assertFalse($verdict->canWrite());
    }
}
