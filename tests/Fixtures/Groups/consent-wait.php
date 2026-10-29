<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use SocKit\Groups\Contracts\MembershipConsent;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\CommandKey;
use SocKit\Support\Data\CommandNamespace;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Tests\Fixtures\Groups\Consent;
use SocKit\Tests\Fixtures\Groups\GroupScenario;

$f = new GroupScenario(install: false);
try {
    $connection = $f->connection();
    $connection->statement("SET statement_timeout='10s'");
    $consent = new Consent($connection);
    $f->app->instance(MembershipConsent::class, $consent);
    $group = new ResourceRef(new PanelId('acme.a'), ResourceKind::Group, $argv[1]);
    $input = new MembershipCommand($group, 'join');
    $command = new CommandEnvelope(key: new CommandKey('membership.lockwait.0001'), namespace: new CommandNamespace(
        installation: $f->handle->actor->installation, panel: $group->panel, integration: 'host.client', commandType: 'groups.join'),
        digest: $f->groups->digest($f->handle, $input, new Revision(1)), expectedRevision: new Revision(1));
    $pid = $connection->scalar('SELECT pg_backend_pid()');
    if (! is_int($pid)) {
        throw new RuntimeException('Invalid contender backend PID');
    }
    fwrite(STDOUT, $pid.PHP_EOL);
    fflush(STDOUT);
    $result = $f->groups->join($f->handle, $input, $command);
    fwrite(STDOUT, json_encode(['result' => $result->toWire(), 'assess' => $consent->assessLevels, 'recheck' => $consent->checkLevels,
        'level' => $connection->transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL);
} finally {
    $f->db->close();
    $f->app->flush();
}
