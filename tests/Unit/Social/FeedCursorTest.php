<?php

declare(strict_types=1);

use SocKit\Errors\Exceptions\SocKitException;
use SocKit\Posts\Data\FeedQuery;
use SocKit\Posts\Exceptions\InvalidFeedCursorException;
use SocKit\Posts\Internal\FeedCursor;
use SocKit\Support\Exceptions\InvalidPayloadException;

it('authenticates and hides a deterministic keyset position bound to canonical context', function (): void {
    $codec = new FeedCursor(['a' => base64_encode(random_bytes(32))], 'a', 60);
    $position = ['time' => '2026-10-29 01:02:03.000000', 'id' => '019a18a0-0000-7000-8000-000000000001'];
    $token = $codec->encode(['panel' => 'acme.a', 'filters' => ['b' => '2', 'a' => '1']], $position, 100);
    expect($token)->not->toContain($position['id'], '2026', 'acme.a');
    expect($codec->decode($token, ['filters' => ['a' => '1', 'b' => '2'], 'panel' => 'acme.a'], 101))->toBe($position);
});

it('rejects tamper malformed length version expiry unknown keys and cross context without restarting', function (string $case): void {
    $key = base64_encode(random_bytes(32));
    $codec = new FeedCursor(['a' => $key], 'a', 60);
    $context = ['installation' => 'host', 'panel' => 'acme.a', 'surface' => ['kind' => 'feed', 'host' => '42', 'incarnation' => 1],
        'viewer' => ['id' => '1', 'generation' => '1'], 'filters' => [], 'order' => 'published_at,id:desc'];
    $token = $codec->encode($context, ['time' => '2026-10-29 01:02:03.000000', 'id' => '019a18a0-0000-7000-8000-000000000001'], 100);
    $now = 101;
    switch ($case) {
        case 'tamper': $token[strlen($token) - 4] = $token[strlen($token) - 4] === 'a' ? 'b' : 'a';
            break;
        case 'length': $token = str_repeat('a', 4097);
            break;
        case 'version': $token = '2'.substr($token, 1);
            break;
        case 'expired': $now = 160;
            break;
        case 'key': $codec = new FeedCursor(['b' => $key], 'b', 60);
            break;
        case 'malformed': $token = '1.a.%%%..';
            break;
        case 'installation': $context['installation'] = 'other';
            break;
        case 'panel': $context['panel'] = 'acme.b';
            break;
        case 'incarnation': $context['surface']['incarnation'] = 2;
            break;
        case 'surface': $context['surface']['host'] = '43';
            break;
        case 'viewer': $context['viewer']['id'] = '2';
            break;
        case 'generation': $context['viewer']['generation'] = '2';
            break;
        case 'filter': $context['filters'] = ['author' => '2'];
            break;
        case 'order': $context['order'] = 'asc';
            break;
    }
    expect(fn () => $codec->decode($token, $context, $now))->toThrow(InvalidFeedCursorException::class);
})->with(['tamper', 'length', 'version', 'expired', 'key', 'malformed', 'installation', 'panel', 'incarnation', 'surface', 'viewer', 'generation', 'filter', 'order']);

it('accepts only retained rotation keys until the original expiry', function (): void {
    $old = base64_encode(random_bytes(32));
    $new = base64_encode(random_bytes(32));
    $position = ['time' => '2026-10-29 01:02:03.000000', 'id' => '019a18a0-0000-7000-8000-000000000001'];
    $token = new FeedCursor(['old' => $old], 'old', 60)->encode([], $position, 100);
    $rotated = new FeedCursor(['old' => $old, 'new' => $new], 'new', 60);
    expect($rotated->decode($token, [], 159))->toBe($position);
    expect(fn () => $rotated->decode($token, [], 160))->toThrow(InvalidFeedCursorException::class);
    expect(fn () => new FeedCursor(['new' => $new], 'new', 60)->decode($token, [], 101))->toThrow(InvalidFeedCursorException::class);
});

it('rejects authenticated malformed positions and never accepts a missing secret or unsupported filter', function (): void {
    $codec = new FeedCursor(['a' => base64_encode(random_bytes(32))], 'a', 60);
    foreach ([['time' => '2026-02-30 01:02:03.000000', 'id' => '019a18a0-0000-7000-8000-000000000001'],
        ['time' => '2026-10-29 01:02:03.000000', 'id' => 'invalid']] as $position) {
        $token = $codec->encode([], $position, 100);
        expect(fn () => $codec->decode($token, [], 101))->toThrow(InvalidFeedCursorException::class);
    }
    expect(fn () => new FeedCursor([], 'a', 60))->toThrow(SocKitException::class);
    expect(fn () => new FeedCursor(['a' => 'acme.public-panel'], 'a', 60))->toThrow(SocKitException::class);
    expect(fn () => new FeedQuery(filters: ['scope' => 'public']))->toThrow(InvalidPayloadException::class);
    expect(fn () => new FeedQuery(limit: 0))->toThrow(InvalidPayloadException::class);
});
