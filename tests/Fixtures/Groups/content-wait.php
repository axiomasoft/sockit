<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use SocKit\Panels\Data\PanelId;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Tests\Fixtures\Groups\ContentScenario;

$s = new ContentScenario(install: false, group: new ResourceRef(new PanelId('acme.a'), ResourceKind::Group, $argv[1]), kind: $argv[4] ?? 'acme.club');
try {
    $s->f->connection()->statement("SET statement_timeout='10s'");
    $mode = $argv[2];
    $post = $s->post();
    $resource = new ResourceRef($s->group->panel, ResourceKind::Post, $argv[3]);
    $command = match ($mode) {
        'post' => $s->command($post, 'group.concurrent.post.0001'),
        'reaction' => $s->reaction($resource, 'group.concurrent.reaction.0001'),
        'leave', 'archive', 'delete' => null,
        default => throw new RuntimeException('Unknown contender'),
    };
    $pid = $s->f->connection()->scalar('SELECT pg_backend_pid()');
    if (! is_int($pid)) {
        throw new RuntimeException('Invalid contender PID');
    }
    fwrite(STDOUT, $pid.PHP_EOL);
    fflush(STDOUT);
    $started = microtime(true);
    $result = match ($mode) {
        'post' => $s->posts->create($s->handle, $post, $command)->operation,
        'reaction' => $s->reactions->add($s->handle, $s->reactionInput($resource), $command),
        'leave' => $s->membership->run($s->group, 'leave'),
        'archive', 'delete' => $s->management($mode),
    };
    fwrite(STDOUT, json_encode(['result' => $result->toWire(), 'level' => $s->f->connection()->transactionLevel(),
        'elapsed' => microtime(true) - $started, 'resolves' => array_values(array_unique($s->f->target->levels))], JSON_THROW_ON_ERROR).PHP_EOL);
} finally {
    $s->f->db->close();
    $s->f->app->flush();
}
