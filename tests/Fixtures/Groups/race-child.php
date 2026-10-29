<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Groups\Data\GroupCommand;
use SocKit\Groups\Data\MembershipCommand;
use SocKit\Groups\Enums\JoinPolicy;
use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ActorRef;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Tests\Fixtures\Groups\MembershipScenario;

$s = new MembershipScenario(install: false);
try {
    [$script, $id, $role, $mode, $action, $actor, $subject, $revision] = $argv;
    $s->actAs($actor);
    $c = $s->f->connection();
    $c->statement("SET statement_timeout='10s'");
    $group = new ResourceRef(new PanelId('acme.a'), ResourceKind::Group, $id);
    $input = $action === 'policy' ? new GroupCommand(JoinPolicy::Closed, resource: $group)
        : new MembershipCommand($group, $action, $subject === '-' ? null : new ActorRef('user', $subject));
    $key = $mode === 'cas' && $role === 'wait' ? 'membership.race.0002' : 'membership.race.0001';
    $command = $s->command($input, new Revision((int) $revision), $key);
    $held = false;
    if ($role === 'hold') {
        $c->listen(function (QueryExecuted $query) use (&$held, $mode, $action): void {
            if (! $held && (($mode === 'consent_rollback' && str_contains($query->sql, 'a_receipts') && str_starts_with(strtolower($query->sql), 'insert')) || ($mode !== 'consent_rollback' && str_contains($query->sql, $action === 'policy' ? 'a_groups' : 'a_group_members') && (str_starts_with($query->sql, 'insert') || str_starts_with($query->sql, 'update'))))) {
                $held = true;
                fwrite(STDOUT, "held\n");
                fflush(STDOUT);
                stream_set_timeout(STDIN, 10);
                if (trim((string) fgets(STDIN)) !== 'release') {
                    throw new RuntimeException('Barrier release missing');
                }
                if (in_array($mode, ['rollback', 'consent_rollback'], true)) {
                    throw new RuntimeException('Injected rollback');
                }
            }
        });
    }
    $pid = $c->scalar('SELECT pg_backend_pid()');
    if (! is_int($pid)) {
        throw new RuntimeException('Invalid backend PID');
    }
    fwrite(STDOUT, $pid.PHP_EOL);
    fflush(STDOUT);
    $result = $input instanceof GroupCommand ? $s->f->groups->setJoinPolicy($s->f->handle, $input, $command) : $s->execute($input, $command);
    fwrite(STDOUT, json_encode(['result' => $result->toWire(), 'held' => $held, 'level' => $c->transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL);
} finally {
    $s->f->db->close();
    $s->f->app->flush();
}
