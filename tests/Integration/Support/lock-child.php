<?php

declare(strict_types=1);

use SocKit\Tests\Integration\Support\PgTarget;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$pdo = PgTarget::fromEnvironment()->connect();
$pdo->exec("SET lock_timeout = '5s'");
echo PgTarget::query($pdo, 'SELECT pg_backend_pid()')->fetchColumn()."\n";
flush();
$pdo->beginTransaction();
if (($argv[1] ?? '') === 'holder') {
    $pdo->exec("UPDATE panel_a.acme_runtime_state SET value='locked' WHERE id='lock'");
    echo "locked\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'release') {
        throw new RuntimeException('Barrier released unexpectedly');
    }
} else {
    $pdo->exec("UPDATE panel_a.acme_runtime_state SET value='child' WHERE id='lock'");
}
$pdo->commit();
echo "committed\n";
