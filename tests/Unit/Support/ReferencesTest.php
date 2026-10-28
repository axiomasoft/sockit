<?php

declare(strict_types=1);

use SocKit\Panels\Data\PanelId;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Data\WireVersion;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidCanonicalPayloadException;
use SocKit\Support\Exceptions\InvalidPayloadException;
use SocKit\Support\Exceptions\UnsupportedWireVersionException;
use SocKit\Support\Ids;
use SocKit\Surfaces\Data\Incarnation;
use SocKit\Surfaces\Data\SurfaceKindId;
use SocKit\Surfaces\Data\SurfaceRef;

it('preserves host and actor values and splits at the first colon', function (): void {
    $ref = HostEntityRef::fromWire('a:b:c');
    expect($ref->type)->toBe('a')->and($ref->value)->toBe('b:c')->and($ref->equals(new HostEntityRef(type: 'a', value: 'b:c')))->toBeTrue();
    expect(ActorRef::fromWire('user: 007 ')->id)->toBe(' 007 ');
});

it('distinguishes panel-qualified identities', function (): void {
    $a = new PanelId('app.first');
    $b = new PanelId('app.second');
    $one = new ResourceRef(panel: $a, kind: ResourceKind::Post, id: 'abc');
    $two = new ResourceRef(panel: $b, kind: ResourceKind::Post, id: 'abc');
    expect($one->equals($two))->toBeFalse()->and(ResourceRef::fromWire($one->toWire())->equals($one))->toBeTrue();
    $surface = new SurfaceRef(panel: $a, kind: new SurfaceKindId('app.feed'), host: HostEntityRef::fromWire('class:1'), incarnation: new Incarnation(1));
    expect(SurfaceRef::fromWire($surface->toWire())->equals($surface))->toBeTrue();
    expect(fn () => SurfaceRef::fromWire(['panel' => '', 'kind' => 'app.feed', 'host' => 'class:1', 'incarnation' => 1]))->toThrow(InvalidPayloadException::class);
});

it('canonicalizes recursively while preserving list order', function (): void {
    $a = ['z' => 1, 'a' => ['y' => 2, 'x' => [1, 2]]];
    $b = ['a' => ['x' => [1, 2], 'y' => 2], 'z' => 1];
    expect(CanonicalJson::encode($a))->toBe(CanonicalJson::encode($b))->and(CanonicalJson::encode([]))->toBe('[]');
    expect(PayloadDigest::of(commandType: 'test', payload: $a)->value)->not->toBe(PayloadDigest::of(commandType: 'test', payload: ['z' => 1, 'a' => ['y' => 2, 'x' => [2, 1]]])->value);
    expect(fn () => CanonicalJson::encode(['a' => ['b' => 1.0]]))->toThrow(InvalidCanonicalPayloadException::class);
    expect(fn () => CanonicalJson::encode([1, 2]))->toThrow(InvalidCanonicalPayloadException::class);
    expect(fn () => CanonicalJson::encode(['object' => new stdClass]))->toThrow(InvalidCanonicalPayloadException::class);
    expect(fn () => CanonicalJson::encode(['utf8' => "\xff"]))->toThrow(InvalidCanonicalPayloadException::class);
});

it('enforces revisions, version compatibility and safe key logging', function (): void {
    expect((new Revision(1))->next()->isAfter(new Revision(1)))->toBeTrue()->and((new Incarnation(1))->next()->value)->toBe(2);
    expect(fn () => (new Revision(PHP_INT_MAX))->next())->toThrow(InvalidPayloadException::class);
    $version = WireVersion::fromWire('1.2');
    expect($version->supports(new WireVersion(major: 1, minor: 1)))->toBeTrue()->and($version->supports(new WireVersion(major: 2, minor: 0)))->toBeFalse();
    expect(fn () => $version->requireSupported(new WireVersion(major: 1, minor: 3)))->toThrow(UnsupportedWireVersionException::class);
    expect((new CommandKey('1234567890123456'))->hashForLog())->toBe(substr(hash('sha256', '1234567890123456'), 0, 16));
    expect(Ids::uuid7())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});
