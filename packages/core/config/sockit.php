<?php

declare(strict_types=1);

return [
    'ids' => [
        'strategy' => 'uuid7',
    ],
    'panels' => [],
    'surfaces' => [],
    'modules' => [
        'enabled' => [],
        'settings' => [],
    ],
    'resolvers' => [
        'tenant' => null,
        'actor' => null,
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
            'allowed_scopes' => ['direct'],
            'default_scope' => 'direct',
            'max_scope' => 'direct',
            'minor_default_scope' => 'direct',
            'minor_max_scope' => 'direct',
            'contour_levels' => [],
        ],
    ],
    'storage' => [
        'connection' => null,
        'table_prefix' => 'sk_',
    ],
    'operations' => [
        'receipt_ttl_seconds' => 86400,
        'outbox_batch_size' => 100,
        'cursor_ttl_seconds' => 3600,
    ],
];
