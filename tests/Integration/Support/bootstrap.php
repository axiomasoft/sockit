<?php

declare(strict_types=1);
use SocKit\Tests\Integration\Support\PgTarget;

foreach (['DATABASE_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    unset($_SERVER[$key], $_ENV[$key]);
    putenv($key);
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
PgTarget::fromEnvironment()->connect();
