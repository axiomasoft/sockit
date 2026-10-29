<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Database\Events\QueryExecuted;
use SocKit\Posts\Contracts\ContentSafety;
use SocKit\Posts\Contracts\MediaResolver;
use SocKit\Posts\Enums\SafetyDecision;
use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Tests\Fixtures\Social\MediaAdapter;
use SocKit\Tests\Fixtures\Social\PostScenario;
use SocKit\Tests\Fixtures\Social\SafetyAdapter;

$s = new PostScenario(install: false);
$role = $argv[1];
$mode = $argv[2];
$connection = $s->connection();
$connection->statement("SET statement_timeout='10s'");
$pid = $connection->scalar('SELECT pg_backend_pid()');
if (! is_int($pid)) {
    throw new RuntimeException('Invalid backend PID');
}
fwrite(STDOUT, $pid.PHP_EOL);
fflush(STDOUT);
$safety = new SafetyAdapter($connection, $mode === 'held' ? SafetyDecision::Hold : SafetyDecision::Allow);
$media = new MediaAdapter($connection);
$s->app->instance(ContentSafety::class, $safety);
$s->app->instance(MediaResolver::class, $media);
$resource = $mode === 'edit' ? new ResourceRef(panel: $s->handle->surface->panel, kind: ResourceKind::Post, id: $argv[3]) : null;
$post = $s->post(body: $role === 'wait' && $mode === 'digest' ? 'Different intent' : 'First post', resource: $resource);
$command = $s->command(post: $post, expected: $mode === 'edit' ? new Revision(1) : null,
    key: $role === 'wait' && $mode === 'edit' ? 'post.edit.request.0002' : 'post.request.0001');
$held = false;
if ($role === 'hold') {
    $connection->listen(function (QueryExecuted $query) use (&$held, $mode): void {
        if (! $held && (str_starts_with($query->sql, 'insert') || str_starts_with($query->sql, 'update')) && str_contains($query->sql, '"driver_a_posts"')) {
            $held = true;
            fwrite(STDOUT, "claimed\n");
            fflush(STDOUT);
            stream_set_timeout(STDIN, 10);
            if (trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('Missing post barrier');
            }
            if ($mode === 'rollback') {
                throw new RuntimeException('Injected post rollback');
            }
        }
    });
}
$result = $resource === null ? $s->posts->create(handle: $s->handle, post: $post, command: $command) : $s->posts->edit(handle: $s->handle, post: $post, command: $command);
if ($role === 'lost') {
    if (! $result->isPublished()) {
        throw new RuntimeException('Lost response write did not publish');
    }
    fwrite(STDERR, "P4.4 response lost after real commit; original key retained\n");
    exit(0);
}
fwrite(STDOUT, json_encode(['result' => $result->operation->toWire(), 'target_levels' => $s->target->levels, 'authority_levels' => $s->authority->levels,
    'captures' => $s->fence->captures, 'checks' => $s->fence->checks, 'safety_levels' => $safety->levels, 'media_levels' => $media->levels,
    'level' => $connection->transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL);
