<?php

declare(strict_types=1);

use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Integration\Support\PgTarget;
use Symfony\Component\Process\Process;

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function consoleProcess(array $arguments, array $environment = []): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Runtime/console-commands.php', ...$arguments], env: $environment);
    $process->run();
    fwrite(STDERR, $process->getErrorOutput());

    return $process;
}

it('runs doctor and bounded relay against isolated panel storage in fresh processes', function (): void {
    $s = new OutboxScenario;
    try {
        $first = $s->event();
        $second = $s->event();
        $s->append($first);
        $s->append($second);
        $s->append($s->event('acme.b'));
        $doctor = consoleProcess(['sockit:doctor', '--panel=acme.a', '--json']);
        // This P2 runtime fixture has no installed P3 history or production constraints.
        expect($doctor->getExitCode())->toBe(1)->and($doctor->getOutput())->toContain('SCHEMA_HISTORY_MISSING');
        $unproven = consoleProcess(['sockit:doctor', '--panel=acme.a'], ['SOCKIT_NO_IDENTITY' => '1']);
        expect($unproven->getExitCode())->toBe(1)->and($unproven->getOutput())->toContain('SCHEMA_HISTORY_MISSING');
        $noHandles = consoleProcess(['sockit:relay', '--panel=acme.a', '--once'], ['SOCKIT_NO_HANDLES' => '1']);
        expect($noHandles->getExitCode())->toBe(1);
        $retry = consoleProcess(['sockit:relay', '--panel=acme.a', '--once', '--limit=1'], ['SOCKIT_CONSOLE_FAULT' => 'retry']);
        expect($retry->getExitCode())->toBe(1, $retry->getOutput())->and($retry->getOutput())->toContain('outbox.retry');
        $rows = PgTarget::query(pdo: $s->db->owner, sql: "SELECT event_id::text, digest, deliveries->'acme.inbox'->>'status' AS status FROM panel_a.driver_a_outbox ORDER BY event_id")->fetchAll(PDO::FETCH_ASSOC);
        $statuses = array_column($rows, 'status');
        sort($statuses);
        expect($statuses)->toBe(['pending', 'retry']);
        $configured = consoleProcess(['sockit:diagnose', '--panel=acme.a', '--json']);
        expect($configured->getOutput())->toContain('configured')->and($configured->getExitCode())->toBe(0);
        $probed = consoleProcess(['sockit:diagnose', '--panel=acme.a', '--json', '--probe-storage']);
        expect($probed->getExitCode())->toBe(1)->and($probed->getOutput())->toContain('SCHEMA_HISTORY_MISSING');
        $projection = consoleProcess(['sockit:outbox:run', '--panel=acme.a', '--replay-mode=projection_only', '--limit=1']);
        expect($projection->getExitCode())->toBe(0);
        $before = array_column($rows, 'digest', 'event_id');
        $retried = null;
        foreach ($rows as $row) {
            if (is_array($row) && ($row['status'] ?? null) === 'retry') {
                $retried = $row['event_id'];
            }
        }
        expect($retried)->toBeString();
        usleep(1100000);
        $run = consoleProcess(['sockit:relay', '--panel=acme.a', '--once', '--limit=1']);
        expect($run->getExitCode())->toBe(0, $run->getOutput())->and($s->count())->toBe(1);
        $after = PgTarget::query(pdo: $s->db->owner, sql: 'SELECT event_id::text, digest FROM panel_a.driver_a_outbox')->fetchAll(PDO::FETCH_KEY_PAIR);
        expect($after)->toEqual($before);
        expect(PgTarget::query(pdo: $s->db->owner, sql: "SELECT event_id::text FROM panel_a.driver_a_outbox WHERE deliveries->'acme.inbox'->>'status' = 'delivered'")->fetchColumn())->toBe($retried);
        expect(PgTarget::query(pdo: $s->db->owner, sql: "SELECT deliveries->'acme.inbox'->>'status' FROM panel_b.other_b_outbox")->fetchColumn())->toBe('pending');
        $s->db->owner->exec('DROP TABLE panel_a.driver_a_receipts');
        $missing = consoleProcess(['sockit:doctor', '--panel=acme.a']);
        expect($missing->getExitCode())->toBe(1)->and($missing->getOutput())->toContain('SCHEMA_HISTORY_MISSING');
    } finally {
        $s->cleanup();
    }
});
