<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use SocKit\Configuration\SockitOptions;
use SocKit\Testing\FakeActorContextResolver;

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
        'operations' => ['receipt_ttl_seconds' => 3600, 'outbox_batch_size' => 50],
    ]);

    expect($valid->enabledModules)->toBe(['posts', 'reactions'])
        ->and($valid->actorResolver)->toBe(FakeActorContextResolver::class)
        ->and($valid->tablePrefix->value())->toBe('sk_');

    expect(fn (): SockitOptions => SockitOptions::fromArray(['unknown_root' => true]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['modules' => ['settings' => ['posts' => ['bad' => static fn (): bool => true]]]]))->toThrow(InvalidConfiguration::class);
    expect(fn (): SockitOptions => SockitOptions::fromArray(['resolvers' => ['actor' => 'NonExistent\\Class']]))->toThrow(InvalidConfiguration::class);
});
