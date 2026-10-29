<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Integration\Support\PgTarget;

$monitor = PgTarget::fromEnvironment()->connect();
$count = static fn (): int => (int) PgTarget::query($monitor, 'SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid <> pg_backend_pid()')->fetchColumn();
$baseline = $count();
$transients = 0;
for ($i = 0; $i < 200; $i++) {
    $scenario = new OutboxScenario;
    $scenario->cleanup();
    unset($scenario);
    gc_collect_cycles();
    $actual = $count();
    if ($actual !== $baseline) {
        $transients++;
        $start = microtime(true);
        $rows = PgTarget::query($monitor, 'SELECT pid,state,query FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid()')->fetchAll(PDO::FETCH_ASSOC);
        fwrite(STDOUT, 'immediate iteration='.$i.' count='.$actual.' rows='.json_encode($rows, JSON_THROW_ON_ERROR).PHP_EOL);
        do {
            usleep(1000);
            $settled = $count();
        } while ($settled !== $baseline && microtime(true) - $start < 1);
        fwrite(STDOUT, 'settled_count='.$settled.' settled_seconds='.(microtime(true) - $start).PHP_EOL);
        if ($settled !== $baseline) {
            throw new RuntimeException('Persistent backend leak');
        }
    }
}
fwrite(STDOUT, 'baseline='.$baseline.' final='.$count().' transient_observations='.$transients.' persistent_leaks=0'.PHP_EOL);
