<?php

declare(strict_types=1);

$path = getenv('SOCKIT_PG_MANIFEST');
if ($path === false || ! is_file($path) || (fileperms($path) & 0777) !== 0600) {
    throw new RuntimeException('Private test manifest required');
}
$manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($manifest)) {
    throw new RuntimeException('Invalid test manifest');
}
foreach (['host', 'user', 'password', 'owner', 'decoy'] as $key) {
    if (! isset($manifest[$key]) || ! is_string($manifest[$key])) {
        throw new RuntimeException('Invalid test manifest field');
    }
}
if (! isset($manifest['port']) || ! is_int($manifest['port'])) {
    throw new RuntimeException('Invalid test manifest port');
}
$base = ['driver' => 'pgsql', 'host' => $manifest['host'], 'port' => $manifest['port'], 'username' => $manifest['user'], 'password' => $manifest['password']];

return ['default' => 'decoy', 'connections' => [
    'owner_a' => [...$base, 'database' => $manifest['owner'], 'prefix' => 'driver_'],
    'owner_b' => [...$base, 'database' => $manifest['owner'], 'prefix' => 'other_'],
    'decoy' => [...$base, 'database' => $manifest['decoy'], 'prefix' => 'driver_'],
]];
