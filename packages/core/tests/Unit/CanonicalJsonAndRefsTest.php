<?php

declare(strict_types=1);

use SocKit\Panels\Data\PanelId;
use SocKit\Support\CanonicalJson;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\HostEntityRef;
use SocKit\Support\Data\InstallationRef;
use SocKit\Support\Data\PayloadDigest;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Data\WireVersion;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Support\Exceptions\InvalidPayloadException;

test('canonical JSON sorts nested keys deterministically and rejects floats or list roots', function (): void {
    $a = CanonicalJson::encode(['z' => 1, 'a' => ['b' => 'тест/путь', 'a' => true]]);
    $b = CanonicalJson::encode(['a' => ['a' => true, 'b' => 'тест/путь'], 'z' => 1]);

    expect($a)->toBe($b)
        ->and($a)->toBe('{"a":{"a":true,"b":"тест/путь"},"z":1}')
        ->and(CanonicalJson::encode([]))->toBe('{}');

    expect(fn (): string => CanonicalJson::encode(['bad' => 1.5]))->toThrow(InvalidPayloadException::class);
    expect(fn (): string => CanonicalJson::encode(['list', 'root']))->toThrow(InvalidPayloadException::class);
});

test('wire references and commands validate bounds and roundtrip without loss', function (): void {
    $host = HostEntityRef::fromWire('user:42');
    $actor = ActorRef::fromWire('user:42');
    $inst = new InstallationRef('inst-1');
    $ns = CommandNamespace::fromWire('inst-1/sockit.default/sockit.posts/posts.publish');
    $key = new CommandKey('req.2026-11-17.0001');
    $digest = PayloadDigest::of('posts.publish', ['body' => 'Hello']);
    $rev = new Revision(1);
    $ver = WireVersion::fromWire('1.0');

    expect($host->toWire())->toBe('user:42')
        ->and($actor->toWire())->toBe('user:42')
        ->and($inst->id)->toBe('inst-1')
        ->and($ns->toWire())->toBe('inst-1/sockit.default/sockit.posts/posts.publish')
        ->and($key->value)->toBe('req.2026-11-17.0001')
        ->and($digest->value)->toStartWith('sha256:')
        ->and($rev->next()->value)->toBe(2)
        ->and($ver->supports(new WireVersion(1, 0)))->toBeTrue();

    expect(fn (): Revision => new Revision(0))->toThrow(InvalidPayloadException::class);
    expect(fn (): ResourceRef => ResourceRef::fromWire(['panel' => 'sockit.default', 'kind' => 'comment', 'id' => '1']))->toThrow(InvalidPayloadException::class);
    expect(ResourceKind::tryFrom('comment'))->toBeNull();
    expect(new ResourceRef(new PanelId('sockit.default'), ResourceKind::Post, 'p1')->kind)->toBe(ResourceKind::Post);
});
