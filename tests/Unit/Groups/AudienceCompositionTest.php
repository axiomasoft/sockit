<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use SocKit\Audience\AudienceEvaluator;
use SocKit\Audience\AudienceGate;
use SocKit\Audience\Contracts\AudienceResolver;
use SocKit\Audience\Contracts\GroupMembershipResolver;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceSubject;
use SocKit\Audience\Data\AuthorizationDecision;
use SocKit\Audience\Data\GroupRef;
use SocKit\Groups\GroupAudienceResolver;
use SocKit\Groups\GroupsServiceProvider;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Testing\FakeAudienceResolver;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('delegates all non-group scopes to the existing evaluator and preserves host containment', function (): void {
    $app = RuntimeScenario::app();
    $fake = RuntimeScenario::trust($app);
    $actor = RuntimeScenario::actor();
    $surface = RuntimeScenario::surface();
    $fake->surfaces->allow($actor, $surface);
    $ref = new HostEntityRef('class', '42');
    $fake->contours->live($actor->actor ?? throw new RuntimeException, 'class', $ref);
    $resolver = new GroupAudienceResolver($app, $fake->contours);
    $gate = new AudienceGate($resolver, $fake->surfaces);
    $existing = new AudienceEvaluator($fake->contours, $fake->groups);
    foreach ([AudienceSpec::direct($actor->actor ?? throw new RuntimeException), AudienceSpec::public(), AudienceSpec::platform(), AudienceSpec::contour('class', $ref)] as $i => $spec) {
        $subject = new AudienceSubject('s'.$i, $spec, null, $surface, $actor->installation);
        expect($resolver->decideRead($actor, $subject))->toEqual($existing->decideRead($actor, $subject));
        $fake->surfaces->containment($actor, $surface, $spec, AuthorizationDecision::unknown('fixture.unproven_set'));
        expect($gate->publish($actor, $subject, Scenario::floor(Scenario::floorWire()))->canRead())->toBeFalse();
    }
    $app->flush();
});

it('denies disabled groups without SQL or host group fallback and preserves scalar batch parity', function (): void {
    $app = RuntimeScenario::app();
    $fake = RuntimeScenario::trust($app);
    $actor = RuntimeScenario::actor();
    $resolver = new GroupAudienceResolver($app, $fake->contours);
    $gate = new AudienceGate($resolver, $fake->surfaces);
    $sqlAttempts = 0;
    $app->bind(ConnectionResolverInterface::class, static function () use (&$sqlAttempts): never {
        $sqlAttempts++;
        throw new RuntimeException('Disabled groups must not resolve SQL');
    });
    $group = new GroupRef('same-id');
    $fake->groups->member($actor->actor ?? throw new RuntimeException, $group);
    $subjects = [];
    foreach (['acme.a', 'acme.b'] as $panel) {
        $surface = RuntimeScenario::surface($panel);
        $fake->surfaces->allow($actor, $surface);
        $subjects[] = new AudienceSubject($panel, AudienceSpec::group($group), $actor->actor, $surface, $actor->installation);
    }
    foreach ($gate->readMany($actor, $subjects) as $key => $verdict) {
        expect($verdict->canRead())->toBeFalse()->and($verdict)->toEqual($gate->read($actor, $subjects[$key === 'acme.a' ? 0 : 1]));
    }
    expect(fn () => $gate->readMany($actor, [$subjects[0], $subjects[0]]))->toThrow(InvalidPayloadException::class);
    expect($sqlAttempts)->toBe(0);
    $app->flush();
});

it('keeps the membership SPI and group wire unchanged and requires explicit resolver opt-in', function (): void {
    $method = new ReflectionMethod(GroupMembershipResolver::class, 'isMember');
    expect(array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters()))->toBe(['actor', 'group']);
    $wire = AudienceSpec::group(new GroupRef('opaque'))->toWire();
    expect($wire)->toBe(['version' => '1.0', 'scope' => 'group', 'group' => 'opaque'])
        ->and(AudienceSpec::fromWire($wire)->toWire())->toBe($wire);
    $app = RuntimeScenario::app(boot: false);
    $app->instance(AudienceResolver::class, $custom = new FakeAudienceResolver);
    $app->register(GroupsServiceProvider::class);
    $app->boot();
    expect($app->make(AudienceResolver::class))->toBe($custom);
    $app->flush();
});
