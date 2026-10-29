<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/Integration/Support/bootstrap.php';

use SocKit\Support\Data\ResourceRef;
use SocKit\Support\Data\Revision;
use SocKit\Support\Enums\ResourceKind;
use SocKit\Tests\Fixtures\Social\PostLifecycle;
use SocKit\Tests\Fixtures\Social\PostScenario;

try {
    $s = new PostScenario(install: false);
    $ref = new ResourceRef($s->handle->surface->panel, ResourceKind::Post, $argv[2] ?? throw new RuntimeException('Missing ID'));
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Missing barrier signal');
    }
    $life = new PostLifecycle($s);
    $action = $argv[1];
    if ($action === 'insert') {
        $post = $s->post('concurrent above cursor');
        $result = $s->posts->create($s->handle, $post, $s->command($post, key: 'feed.worker.insert.0001'));
    } elseif ($action === 'edit') {
        $post = $s->post('concurrent edit', resource: $ref);
        $result = $s->posts->edit($s->handle, $post, $s->command($post, new Revision(1), 'feed.worker.edit.0001'));
    } elseif ($action === 'restore') {
        if (! $life->execute($ref, 'hide', 1)->operation->isSuccess()) {
            throw new RuntimeException('Worker hide failed');
        }
        $result = $life->execute($ref, 'restore', 2);
    } elseif ($action === 'delete') {
        $result = $life->execute($ref, 'delete', 1);
    } else {
        throw new RuntimeException('Invalid worker action');
    }
    if (! $result->operation->isSuccess()) {
        throw new RuntimeException('Worker mutation failed: '.$result->operation->problem?->value);
    }
    $s->assertSentinels();
    fwrite(STDOUT, 'COMMITTED');
} catch (Throwable $error) {
    fwrite(STDERR, (string) $error);
    exit(1);
}
