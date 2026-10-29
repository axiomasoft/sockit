<?php

declare(strict_types=1);

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Social\PostRace;
use SocKit\Tests\Fixtures\Social\PostScenario;

it('serializes concurrent post claims and edits on independent PG backends without retries', function (string $mode): void {
    $s = new PostScenario;
    try {
        $ref = null;
        if ($mode === 'edit') {
            $ref = $s->posts->create(handle: $s->handle, post: $s->post(), command: $s->command())->operation->resource;
            expect($ref)->not->toBeNull();
        }
        [$a, $b] = PostRace::run($s, $mode, $ref);
        if ($mode === 'rollback') {
            expect($a->problem)->toBe(ProblemCode::ProviderUnavailable)->and($b->isSuccess())->toBeTrue();
        } else {
            expect($a->isSuccess())->toBeTrue();
            if ($mode === 'same') {
                expect($b->isSuccess())->toBeTrue()->and($b->resource)->toEqual($a->resource)->and($b->revision)->toEqual($a->revision);
            } else {
                expect($b->isSuccess())->toBeFalse()->and($b->resource)->toBeNull()->and($b->problem)->toBe(match ($mode) {
                    'edit' => ProblemCode::RevisionConflict, 'digest' => ProblemCode::IdempotencyConflict, default => ProblemCode::ContentHeld,
                });
            }
        }
        $counts = $s->counts();
        expect($counts['posts'])->toBe(1)->and($counts['revisions'])->toBe($mode === 'edit' ? 2 : 1)->and($counts['outbox'])->toBe($mode === 'edit' ? 2 : 1);
        expect($s->connection()->table('panel_a.a_posts')->value('revision'))->toBe($mode === 'edit' ? 2 : 1);
        expect($counts['receipts'])->toBe($mode === 'edit' ? 3 : 1);
    } finally {
        $s->cleanup();
    }
})->with(['same', 'edit', 'digest', 'rollback', 'held']);

it('recovers a lost response by original key without a duplicate resource revision or publication', function (): void {
    $s = new PostScenario;
    try {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Social/post-child.php', 'lost', 'same', ''], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start lost-response writer');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        expect(proc_close($process))->toBe(0, (string) $error)->and(trim((string) $output))->toMatch('/^[1-9][0-9]*$/');
        $command = $s->command();
        $found = $s->posts->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
        expect($found?->isPublished())->toBeTrue();
        $retry = $s->posts->create(handle: $s->handle, post: $s->post(), command: $command);
        expect($retry->operation->resource)->toEqual($found?->operation->resource)->and($retry->operation->revision)->toEqual(new Revision(1));
        expect($s->counts())->toBe(['posts' => 1, 'revisions' => 1, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});
