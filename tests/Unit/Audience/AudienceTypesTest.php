<?php

declare(strict_types=1);

use SocKit\Actors\Data\ActorContext;
use SocKit\Actors\Enums\ActorKind;
use SocKit\Actors\Enums\AgeBand;
use SocKit\Actors\Enums\ContextProvenance;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Audience\Data\GroupRef;
use SocKit\Audience\Data\PrivacyDescriptor;
use SocKit\Audience\Enums\Scope;
use SocKit\Audience\Exceptions\FloorWidensException;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Exceptions\UnsupportedWireVersionException;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('round trips every audience form with exactly one shape', function (): void {
    foreach ([AudienceSpec::direct(ActorRef::fromWire('person:a:b')), AudienceSpec::group(new GroupRef('club')), AudienceSpec::contour(level: 'team', contour: HostEntityRef::fromWire('crm.team:support')), AudienceSpec::platform(), AudienceSpec::public()] as $spec) {
        expect(AudienceSpec::fromWire($spec->toWire())->toWire())->toBe($spec->toWire());
    }
});

it('rejects ambiguous malformed empty and duplicate audience wires', function (array $wire): void {
    expect(fn () => AudienceSpec::fromWire(Scenario::object($wire)))->toThrow(InvalidPayloadException::class);
})->with([
    [[]],
    [['version' => '1.0', 'scope' => 'allOf']],
    [['version' => '1.0', 'scope' => 'public', 'actors' => ['user:1']]],
    [['version' => '1.0', 'scope' => 'direct', 'actors' => []]],
    [['version' => '1.0', 'scope' => 'direct', 'actors' => ['user:1', 'user:1']]],
    [['version' => '1.0', 'scope' => 'direct', 'actors' => [42]]],
    [['version' => '1.0', 'scope' => 'direct', 'actors' => ['key' => 'user:1']]],
    [['version' => '1.0', 'scope' => 'group', 'group' => '']],
    [['version' => '1.0', 'scope' => 'group', 'group' => str_repeat('a', 65)]],
    [['version' => '1.0', 'scope' => 'contour', 'contour_level' => 'Class', 'contour' => 'class:1']],
    [['version' => '1.0', 'scope' => 'contour', 'contour_level' => 'class']],
    [['version' => '1.0', 'scope' => 'contour', 'contour_level' => 'class', 'contour' => 'class:1', 'group' => 'x']],
]);

it('rejects unsupported audience versions', function (string $version): void {
    expect(fn () => AudienceSpec::fromWire(['version' => $version, 'scope' => 'public']))->toThrow(UnsupportedWireVersionException::class);
})->with(['2.0', '1.1']);

it('bounds direct audiences at 500 unique actors', function (): void {
    $actors = array_map(fn (int $i): ActorRef => new ActorRef(type: 'user', id: (string) $i), range(1, 500));
    expect(AudienceSpec::direct(...$actors)->actors)->toHaveCount(500);
    $actors[] = ActorRef::fromWire('user:501');
    expect(fn () => AudienceSpec::direct(...$actors))->toThrow(InvalidPayloadException::class);
});

it('uses minor defaults and ceilings for both minor and unknown age', function (AgeBand $age): void {
    $floor = Scenario::floor(Scenario::floorWire());
    $viewer = new ActorContext(actor: ActorRef::fromWire('user:1'), kind: ActorKind::Person, installation: new InstallationRef('i1'), ageBand: $age, provenance: ContextProvenance::Direct);
    expect($floor->maxFor($viewer))->toBe($age === AgeBand::Adult ? Scope::Public : Scope::Contour)
        ->and($floor->defaultFor($viewer))->toBe($age === AgeBand::Adult ? Scope::Contour : Scope::Direct);
})->with(AgeBand::cases());

it('checks every narrowing parameter rather than just max scope', function (array $changes): void {
    $platformWire = Scenario::floorWire();
    $platformWire['default_scope'] = 'group';
    $platformWire['minor_default_scope'] = 'direct';
    $platform = Scenario::floor($platformWire);
    $surface = Scenario::floor(array_replace($platformWire, $changes));
    expect(fn () => $platform->narrowedBy($surface))->toThrow(FloorWidensException::class);
})->with([
    [['default_scope' => 'contour']],
    [['minor_default_scope' => 'group']],
    [['minor_max_scope' => 'platform']],
    [['contour_levels' => ['class', 'school', 'region']]],
    [['contour_levels' => ['school', 'class']]],
]);

it('rejects descriptor data outside the adopted wire contract', function (array $change): void {
    $wire = ['scope' => 'contour', ...Scenario::floorWire(), 'contour_level' => 'class'];
    expect(fn () => PrivacyDescriptor::fromContractArray(Scenario::object(array_replace($wire, $change))))->toThrow(InvalidPayloadException::class);
})->with([
    [['contour_level' => 'region']],
    [['group_id' => 'club']],
    [['contour_level' => null]],
    [['extra' => 'ignored']],
    [['scope' => 'unknown']],
    [['allowed_scopes' => ['direct', 1]]],
]);

it('restricts audience reasons to the native contract dictionary', function (): void {
    expect(fn () => AudienceVerdict::allow('audience.allowed.author'))->toThrow(InvalidPayloadException::class);
    expect(fn () => AudienceVerdict::allow('audience.unknown'))->toThrow(InvalidPayloadException::class);
    expect(fn () => AudienceVerdict::allow('audience.resolver_error'))->toThrow(InvalidPayloadException::class);
    expect(fn () => AudienceVerdict::allow('audience.allowed.observer'))->toThrow(InvalidPayloadException::class);
    expect(fn () => AudienceVerdict::deny('audience.allowed.public'))->toThrow(InvalidPayloadException::class);
    expect(AudienceVerdict::allowReadOnly('audience.allowed.observer')->canRead())->toBeTrue()
        ->and(AudienceVerdict::allowReadOnly('audience.allowed.observer')->canWrite())->toBeFalse()
        ->and(AudienceVerdict::deny('audience.unknown')->canRead())->toBeFalse();
});
