<?php

declare(strict_types=1);

return [
    // Per-connection trusted cluster identity and canonical endpoint + approved aliases.
    // Data only: ['social' => ['cluster' => 'cluster_a', 'endpoint' => ['host' => 'db', 'port' => 5432], 'aliases' => []]].
    // Used by explicit storage preflight; no discovery or DDL at boot.
    'storage' => ['deployments' => []],
    'events' => ['source_generation' => '1', 'lease_seconds' => 30, 'retry_seconds' => 5, 'max_attempts' => 10],
    'ids' => ['strategy' => 'uuid7'],
    'panels' => [], // FQCN declarations; panel compilation is introduced in P1.13.
    'surfaces' => [], // SurfaceProvider FQCN declarations.
    'modules' => ['enabled' => [], 'settings' => []],
    // Host resolvers are scoped. Null fails closed when the corresponding SPI is resolved.
    'resolvers' => ['tenant' => null, 'actor' => null, 'contour' => null, 'audience' => null, 'authorizer' => null, 'groups' => null, 'surface_policy' => null, 'targets' => null],
    'privacy' => ['floor' => [
        'allowed_scopes' => ['direct'], 'default_scope' => 'direct', 'max_scope' => 'direct',
        'minor_default_scope' => 'direct', 'minor_max_scope' => 'direct', 'contour_levels' => [],
    ]],
];
