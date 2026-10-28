<?php

declare(strict_types=1);

use Illuminate\Console\Application as Artisan;
use Illuminate\Events\Dispatcher;
use SocKit\Audience\Data\AudienceVerdict;
use SocKit\Panels\Data\PanelId;
use SocKit\Tests\Fixtures\Runtime\OutboxScenario;
use SocKit\Tests\Integration\Support\PgTarget;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$scenario = new OutboxScenario(install: false);
if (getenv('SOCKIT_NO_IDENTITY') !== '1') {
    $scenario->app->make('config')->set('sockit.deployment_identity', 'owned-test-cluster');
}
$scenario->consumer->fault = getenv('SOCKIT_CONSOLE_FAULT') ?: '';
if (getenv('SOCKIT_NO_HANDLES') !== '1') {
    $scenario->app->bind('sockit.relay.handles', function ($app, array $parameters) use ($scenario): array {
        $panel = $parameters['panel'] ?? null;
        if (! $panel instanceof PanelId) {
            throw new RuntimeException('Invalid panel');
        }

        return [$scenario->handle($panel->value)];
    });
}
foreach (PgTarget::query(pdo: $scenario->db->owner, sql: 'SELECT event_id FROM panel_a.driver_a_outbox')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    if (! is_string($id)) {
        throw new RuntimeException('Invalid event ID');
    }
    $scenario->fake->audience->verdict(key: $id, verdict: AudienceVerdict::allow('audience.allowed.platform'));
}
$artisan = new Artisan($scenario->app, new Dispatcher($scenario->app), 'test');
exit($artisan->run(new ArgvInput, new ConsoleOutput));
