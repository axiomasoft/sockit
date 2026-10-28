<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Audience\AllowAuthenticatedPersonAuthorizer;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\ActionAuthorizer;
use SocKit\Audience\Contracts\SurfaceAudiencePolicy;
use SocKit\Audience\Data\ActionRequest;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Testing\FakeActionAuthorizer;
use SocKit\Testing\SocKitFake;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('requires an explicit allow for action authorization', function (): void {
    $unknown = AuthorizationDecision::unknown('host.unavailable');
    $deny = AuthorizationDecision::deny('host.forbidden');
    $allow = AuthorizationDecision::allow('host.allowed');
    expect($unknown->isAllowed())->toBeFalse()->and($deny->isAllowed())->toBeFalse()->and($allow->isAllowed())->toBeTrue();
    $allow->requireAllowed();
    foreach ([$unknown, $deny] as $decision) {
        try {
            $decision->requireAllowed();
            Assert::fail('Non-allow authorization accepted');
        } catch (AuthorizationDeniedException $e) {
            expect($e->problem()->value)->toBe($decision === $unknown ? 'sockit.authorization_unknown' : 'sockit.authorization_denied')
                ->and($e->detail()['reason'])->toBe($decision->reason);
        }
    }
});

it('offers an opt-in authorizer only for person and service principals', function (ActorKind $kind): void {
    $actor = new ActorContext(actor: $kind === ActorKind::Anonymous ? null : ActorRef::fromWire('user:1'), kind: $kind, installation: new InstallationRef('i1'), ageBand: $kind === ActorKind::Anonymous ? AgeBand::Unknown : AgeBand::Adult, provenance: ContextProvenance::Direct);
    $decision = (new AllowAuthenticatedPersonAuthorizer)->decide(new ActionRequest(actor: $actor, action: 'sockit.posts.publish', surface: Scenario::subject(Scenario::subjectWire())->surface));
    expect($decision->isAllowed())->toBe(in_array($kind, [ActorKind::Person, ActorKind::Service], true));
})->with(ActorKind::cases());

it('rejects malformed actions and cross-panel resource references', function (): void {
    $surface = Scenario::subject(Scenario::subjectWire())->surface;
    foreach (['', 'Upper.Action', str_repeat('a', 121), "sockit.posts.publish\n"] as $action) {
        expect(fn () => new ActionRequest(actor: Scenario::viewer(), action: $action, surface: $surface))->toThrow(InvalidPayloadException::class);
    }
    $resource = ResourceRef::fromWire(['panel' => 'other.panel', 'kind' => 'post', 'id' => '42']);
    expect(fn () => new ActionRequest(actor: Scenario::viewer(), action: 'sockit.posts.hide', surface: $surface, resource: $resource))->toThrow(InvalidPayloadException::class);
});

it('defaults fake authorization to deny and permits explicit deny unknown and allow', function (): void {
    $request = new ActionRequest(actor: Scenario::viewer(), action: 'sockit.posts.publish', surface: Scenario::subject(Scenario::subjectWire())->surface);
    $fake = new FakeActionAuthorizer;
    expect($fake->decide($request)->reason)->toBe('fake.not_configured');
    $fake->allow($request->action);
    expect($fake->decide($request)->isAllowed())->toBeTrue();
    $fake->deny(action: $request->action, reason: 'host.denied');
    expect($fake->decide($request)->reason)->toBe('host.denied');
    $fake->unknown($request->action);
    expect($fake->decide($request)->isAllowed())->toBeFalse();
});

it('installs all available fakes explicitly and keeps unknown surface access closed', function (): void {
    $app = new Application;
    $fake = SocKitFake::install(app: $app, installation: new InstallationRef('i1'));
    expect($app->make(SurfaceAudiencePolicy::class))->toBe($fake->surfaces)
        ->and($app->make(ActionAuthorizer::class))->toBe($fake->authorizer)
        ->and($app->make(AudienceGate::class)->read(viewer: Scenario::viewer(), subject: Scenario::subject(Scenario::subjectWire()))->canRead())->toBeFalse();
    $second = SocKitFake::install(app: $app, installation: new InstallationRef('i2'));
    expect($second->surfaces)->not->toBe($fake->surfaces)
        ->and($app->make(SurfaceAudiencePolicy::class))->toBe($second->surfaces);
    $app->flush();
});
