<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use SocKit\Configuration\SockitOptions;
use SocKit\Testing\Fakes\Actors\FakeActorContextResolver;

test('SockitOptions parses closed valid config cleanly and rejects unknown keys or closures', function (): void {
    $valid = SockitOptions::fromArray([
        'ids' => ['strategy' => 'uuid7'],
        'panels' => [],
        'surfaces' => [],
        'modules' => ['enabled' => ['posts', 'reactions'], 'settings' => ['posts' => ['max_body_bytes' => 4096]]],
        'resolvers' => [
            'tenant' => null,
            'actor' => FakeActorContextResolver::class,
            'contour' => null,
            'audience' => null,
            'authorizer' => null,
            'groups' => null,
            'consent' => null,
            'surface_policy' => null,
            'targets' => null,
        ],
        'privacy' => [
            'floor' => [
                'allowed_scopes' => ['direct', 'platform'],
                'default_scope' => 'platform',
                'max_scope' => 'platform',
                'minor_default_scope' => 'direct',
                'minor_max_scope' => 'direct',
                'contour_levels' => [],
            ],
        ],
        'storage' => ['connection' => 'sqlite', 'table_prefix' => 'sk_'],
        'operations' => ['receipt_ttl_seconds' => 3600, 'outbox_batch_size' => 50, 'cursor_ttl_seconds' => 1800],
    ]);

    expect($valid->enabledModules)->toBe(['posts', 'reactions'])
        ->and($valid->actorResolver)->toBe(FakeActorContextResolver::class)
        ->and($valid->cursorTtlSeconds)->toBe(1800)
        ->and($valid->tablePrefix->value())->toBe('sk_');

    expect(fn (): SockitOptions => SockitOptions::fromArray(['unknown_root' => true]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['modules' => ['enabled' => ['unknown.module']]]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['modules' => ['enabled' => ['posts', 'sockit.posts']]]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['modules' => ['settings' => ['unknown.module' => ['flag' => true]]]]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['modules' => ['settings' => ['posts' => ['unknown_key' => true]]]]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['modules' => ['settings' => ['posts' => ['max_body_bytes' => -5]]]]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['modules' => ['settings' => ['posts' => ['max_body_bytes' => static fn (): bool => true]]]]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['resolvers' => ['actor' => 'NonExistent\\Class']]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['resolvers' => ['consent' => 'NonExistent\\Class']]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['operations' => ['cursor_ttl_seconds' => 10]]))->toThrow(InvalidConfiguration::class);
});
