<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Actors\Exceptions\InvalidActorContextException;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\InstallationRef;

it('creates an explicit anonymous context without an actor', function (): void {
    $context = ActorContext::anonymous(installation: new InstallationRef('app'), provenance: ContextProvenance::Http);

    expect($context->actor)->toBeNull()
        ->and($context->kind)->toBe(ActorKind::Anonymous)
        ->and($context->ageBand)->toBe(AgeBand::Unknown)
        ->and($context->installation->id)->toBe('app')
        ->and($context->provenance)->toBe(ContextProvenance::Http)
        ->and($context->onBehalfOf)->toBeNull()
        ->and($context->generation)->toBe('0')
        ->and($context->isMinorForPolicy())->toBeTrue()
        ->and($context->sameActor(new ActorRef(type: 'user', id: '1')))->toBeFalse();
});

it('rejects invalid actor contexts with a stable problem code', function (ActorKind $kind, bool $hasActor, AgeBand $ageBand, bool $delegated, string $generation): void {
    try {
        new ActorContext(
            actor: $hasActor ? new ActorRef(type: 'user', id: '1') : null,
            kind: $kind,
            installation: new InstallationRef('app'),
            ageBand: $ageBand,
            provenance: ContextProvenance::Direct,
            onBehalfOf: $delegated ? new ActorRef(type: 'school', id: '7') : null,
            generation: $generation,
        );
        Assert::fail('Invalid actor context was accepted');
    } catch (InvalidActorContextException $exception) {
        expect($exception->problem())->toBe(ProblemCode::ActorContextInvalid);
    }
})->with([
    'anonymous with actor' => [ActorKind::Anonymous, true, AgeBand::Unknown, false, '0'],
    'person without actor' => [ActorKind::Person, false, AgeBand::Adult, false, '0'],
    'service without actor' => [ActorKind::Service, false, AgeBand::Adult, false, '0'],
    'system without actor' => [ActorKind::System, false, AgeBand::Unknown, false, '0'],
    'anonymous adult' => [ActorKind::Anonymous, false, AgeBand::Adult, false, '0'],
    'anonymous minor' => [ActorKind::Anonymous, false, AgeBand::Minor, false, '0'],
    'person delegation' => [ActorKind::Person, true, AgeBand::Adult, true, '0'],
    'system delegation' => [ActorKind::System, true, AgeBand::Adult, true, '0'],
    'anonymous delegation' => [ActorKind::Anonymous, false, AgeBand::Unknown, true, '0'],
    'empty generation' => [ActorKind::Person, true, AgeBand::Adult, false, ''],
    'oversized generation' => [ActorKind::Person, true, AgeBand::Adult, false, str_repeat('g', 65)],
]);

it('treats unknown age as minor for policy', function (AgeBand $ageBand, bool $expected): void {
    $context = new ActorContext(
        actor: new ActorRef(type: 'user', id: '1'),
        kind: ActorKind::Person,
        installation: new InstallationRef('app'),
        ageBand: $ageBand,
        provenance: ContextProvenance::Direct,
        generation: str_repeat('g', 64),
    );

    expect($context->isMinorForPolicy())->toBe($expected)
        ->and($context->sameActor(new ActorRef(type: 'user', id: '1')))->toBeTrue()
        ->and($context->sameActor(new ActorRef(type: 'service', id: '1')))->toBeFalse()
        ->and($context->sameActor(new ActorRef(type: 'user', id: '2')))->toBeFalse();
})->with([[AgeBand::Adult, false], [AgeBand::Minor, true], [AgeBand::Unknown, true]]);

it('supports service delegation and system principals', function (): void {
    $school = new ActorRef(type: 'school', id: '7');
    $context = new ActorContext(
        actor: new ActorRef(type: 'service', id: 'announcements'),
        kind: ActorKind::Service,
        installation: new InstallationRef('app'),
        ageBand: AgeBand::Adult,
        provenance: ContextProvenance::System,
        onBehalfOf: $school,
    );
    $system = new ActorContext(
        actor: new ActorRef(type: 'system', id: 'maintenance'),
        kind: ActorKind::System,
        installation: new InstallationRef('app'),
        ageBand: AgeBand::Unknown,
        provenance: ContextProvenance::System,
    );

    expect($context->onBehalfOf)->toBe($school)
        ->and($context->isMinorForPolicy())->toBeFalse()
        ->and($system->kind)->toBe(ActorKind::System);
});
