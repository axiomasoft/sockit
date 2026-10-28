<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Integration\Support\PgTarget;

it('releases PostgreSQL sessions across repeated complete scenario teardowns', function (): void {
    $monitor = PgTarget::fromEnvironment()->connect();
    $count = static fn (): int => (int) PgTarget::query($monitor, 'SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid <> pg_backend_pid()')->fetchColumn();
    $baseline = $count();
    for ($i = 0; $i < 35; $i++) {
        $scenario = new OutboxScenario;
        $scenario->cleanup();
        unset($scenario);
        gc_collect_cycles();
        expect($count())->toBe($baseline);
    }
    fwrite(STDERR, 'Repeated teardown: baseline='.$baseline.' final='.$count().PHP_EOL);
});
