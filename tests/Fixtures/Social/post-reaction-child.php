<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Audience\Data\AudienceSpec;
use SocKit\Errors\Enums\ProblemCode;
use SocKit\Operations\Data\OperationResult;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Tests\Fixtures\Runtime\RuntimeScenario;
use SocKit\Tests\Fixtures\Social\PostReactionScenario;

$f = new PostReactionScenario(install: false);
$s = $f->s;
[$script, $role, $first, $action, $id] = $argv;
$reaction = ($role === 'hold') === ($first === 'reaction');
if ($reaction) {
    $f->actor(RuntimeScenario::actor('8'));
}
$c = $s->connection();
$c->statement("SET statement_timeout='12s'");
$pid = $c->scalar('SELECT pg_backend_pid()');
if (! is_int($pid)) {
    throw new RuntimeException('Missing backend PID');
}
fwrite(STDOUT, $pid.PHP_EOL);
fflush(STDOUT);
$pause = static function (): void {
    fwrite(STDOUT, "post-locked\n");
    fflush(STDOUT);
    stream_set_timeout(STDIN, 10);
    if (trim((string) fgets(STDIN)) !== 'release') {
        throw new RuntimeException('Missing post/reaction barrier release');
    }
};
$held = false;
if ($role === 'hold' && $action !== 'revoke') {
    $c->listen(function (QueryExecuted $query) use (&$held, $pause): void {
        if (! $held && str_contains($query->sql, '"driver_a_posts"') && str_contains($query->sql, 'for update')) {
            $held = true;
            $pause();
        }
    });
}
$ref = new ResourceRef(panel: $s->handle->surface->panel, kind: ResourceKind::Post, id: $id);
if ($role === 'hold' && $action === 'revoke') {
    // Host-owned revoker: surface -> authority follows the public operation's lock order.
    $c->transaction(function () use ($c, $pause): void {
        $c->table('panel_a.a_surface_instances')->lockForUpdate()->first();
        $pause();
        $c->table('panel_a.envelope_authority')->update(['allowed' => false, 'generation' => 2]);
    });
    $result = OperationResult::rejected(ProblemCode::AuthorizationDenied);
} elseif ($reaction) {
    $result = $f->add($f->target($ref));
} else {
    $result = $f->life->execute($ref, $action, 1,
        $action === 'audience' ? AudienceSpec::direct(RuntimeScenario::actor()->actor ?? throw new RuntimeException('Owner')) : null)->operation;
}
fwrite(STDOUT, json_encode(['result' => $result->toWire(), 'level' => $c->transactionLevel(), 'host_levels' => $s->target->levels,
    'authority_levels' => $s->authority->levels, 'checks' => $s->fence->checks, 'host_fences' => $s->target->fences], JSON_THROW_ON_ERROR).PHP_EOL);
