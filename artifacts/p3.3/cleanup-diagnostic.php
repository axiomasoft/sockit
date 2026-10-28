<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Integration\Support\PgTarget;

$monitor = PgTarget::fromEnvironment()->connect();
$query = 'SELECT pid, state, query, backend_start::text FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() ORDER BY pid';
$baseline = PgTarget::query($monitor, $query)->fetchAll(PDO::FETCH_ASSOC);
fwrite(STDOUT, 'baseline='.json_encode($baseline, JSON_THROW_ON_ERROR).PHP_EOL);
$transients = 0;
for ($i = 0; $i < 70; $i++) {
    $scenario = new OutboxScenario;
    $scenario->cleanup();
    unset($scenario);
    gc_collect_cycles();
    $rows = PgTarget::query($monitor, $query)->fetchAll(PDO::FETCH_ASSOC);
    if ($rows !== $baseline) {
        $transients++;
        $start = microtime(true);
        fwrite(STDOUT, 'immediate iteration='.$i.' rows='.json_encode($rows, JSON_THROW_ON_ERROR).PHP_EOL);
        do {
            usleep(1000);
            $rows = PgTarget::query($monitor, $query)->fetchAll(PDO::FETCH_ASSOC);
        } while ($rows !== $baseline && microtime(true) - $start < 1);
        fwrite(STDOUT, 'settled_seconds='.(microtime(true) - $start).' rows='.json_encode($rows, JSON_THROW_ON_ERROR).PHP_EOL);
        if ($rows !== $baseline) {
            throw new RuntimeException('Persistent backend leak');
        }
    }
}
fwrite(STDOUT, 'transient_observations='.$transients.' persistent_leaks=0'.PHP_EOL);
