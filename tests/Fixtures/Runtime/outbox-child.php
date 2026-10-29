<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Operations\Data\EventEnvelope;
use SocKit\Operations\Enums\ReplayMode;
use SocKit\Operations\OutboxRelay;
use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Integration\Support\PgTarget;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$scenario = new OutboxScenario(install: false);
register_shutdown_function(static function () use ($scenario): void {
    $scenario->consumer->close();
    $scenario->db->close();
});
$connection = $scenario->db->capsule->getConnection('owner_a');
$identity = $connection->selectOne('SELECT pg_backend_pid() AS pid');
if (! $identity instanceof stdClass || ! is_int($identity->pid)) {
    throw new RuntimeException('Invalid relay backend PID');
}
echo $identity->pid."\n";
fflush(STDOUT);
$action = $argv[1];
if ($action === 'inbox') {
    echo $scenario->consumer->backendPid()."\n";
    fflush(STDOUT);
    $wire = PgTarget::query(pdo: $scenario->db->owner, sql: 'SELECT envelope FROM panel_a.driver_a_outbox')->fetchColumn();
    if (! is_string($wire)) {
        throw new RuntimeException('Missing inbox event');
    }
    $decoded = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Invalid fixture envelope');
    }
    $fields = [];
    foreach ($decoded as $key => $value) {
        if (! is_string($key)) {
            throw new RuntimeException('Invalid fixture envelope key');
        }
        $fields[$key] = $value;
    }
    $event = EventEnvelope::fromWire($fields);
    $result = $scenario->consumer->consume(event: $event, mode: ReplayMode::Delivery);
    echo $result->status."\n";
    exit(0);
}
if ($action === 'hold') {
    $connection->listen(static function (QueryExecuted $query): void {
        if (str_contains(strtolower($query->sql), 'for update skip locked')) {
            echo "locked\n";
            fflush(STDOUT);
            if (trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Missing claim barrier release');
            }
        }
    });
}
if ($action === 'hold' || $action === 'claim') {
    $lease = $scenario->store()->claim();
    echo $lease === null ? "none\n" : $lease->token."\n";
    exit(0);
}
$scenario->consumer->fault = $action;
$handle = $scenario->handle();
// Fixture current authority supplies event IDs to the default-deny audience fake.
$rows = PgTarget::query(pdo: $scenario->db->owner, sql: 'SELECT event_id FROM panel_a.driver_a_outbox')->fetchAll(PDO::FETCH_COLUMN);
foreach ($rows as $id) {
    if (! is_string($id)) {
        throw new RuntimeException('Invalid event ID');
    }
    $scenario->fake->audience->verdict(key: $id, verdict: AudienceVerdict::allow('audience.allowed.platform'));
}
$scenario->app->make(OutboxRelay::class)->poll($handle);
throw new RuntimeException('Crash injection did not terminate the worker');
