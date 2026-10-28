<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Integration\Support\PgTarget;

function countPgClientBackends(PDO $monitor): int
{
    // Autovacuum workers may enter this database after fixture DDL. They are not leaked PDOs.
    return (int) PgTarget::query($monitor, "SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid <> pg_backend_pid() AND backend_type='client backend'")->fetchColumn();
}

it('releases PostgreSQL sessions across repeated complete scenario teardowns', function (): void {
    $monitor = PgTarget::fromEnvironment()->connect();
    $baseline = countPgClientBackends($monitor);
    for ($i = 0; $i < 35; $i++) {
        $scenario = new OutboxScenario;
        $scenario->cleanup();
        unset($scenario);
        gc_collect_cycles();
        expect(countPgClientBackends($monitor))->toBe($baseline);
    }
    fwrite(STDERR, 'Repeated teardown: baseline='.$baseline.' final='.countPgClientBackends($monitor).PHP_EOL);
});

it('still detects an actual retained PDO client backend until it closes', function (): void {
    $target = PgTarget::fromEnvironment();
    $monitor = $target->connect();
    $baseline = countPgClientBackends($monitor);
    $retained = $target->connect();
    expect(countPgClientBackends($monitor))->toBe($baseline + 1);
    unset($retained);
    expect(countPgClientBackends($monitor))->toBe($baseline);
    fwrite(STDERR, 'Teardown negative control: retained_client_detected=true released_client_removed=true'.PHP_EOL);
});
