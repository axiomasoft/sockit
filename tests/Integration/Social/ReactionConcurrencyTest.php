<?php

declare(strict_types=1);

use SocKit\Errors\Enums\ProblemCode;
use SocKit\Support\Data\Revision;
use SocKit\Tests\Fixtures\Social\ReactionRace;
use SocKit\Tests\Fixtures\Social\ReactionScenario;

it('serializes real competing reaction envelopes with exact membership counter revision receipt and outbox cardinalities', function (string $mode): void {
    $s = new ReactionScenario;
    try {
        if ($mode === 'add-remove') {
            expect($s->execute($s->command(key: 'reaction.initial.001'))->isSuccess())->toBeTrue();
            expect($s->execute(command: $s->command(action: 'remove', expected: new Revision(1), key: 'reaction.initial.002'), action: 'remove')->isSuccess())->toBeTrue();
        }
        [$holder, $waiter] = ReactionRace::run(s: $s, mode: $mode);
        $expectedProblem = match ($mode) {
            'digest' => ProblemCode::IdempotencyConflict, 'different', 'add-remove' => ProblemCode::RevisionConflict,
            'revoke-rollback' => ProblemCode::AuthorizationDenied, 'generation-rollback' => ProblemCode::AuthorizationUnknown, default => null,
        };
        expect($waiter->problem)->toBe($expectedProblem)->and($waiter->isSuccess())->toBe($expectedProblem === null);
        if ($mode === 'same') {
            expect($holder)->toEqual($waiter);
        }
        if (in_array($mode, ['rollback', 'revoke-rollback', 'generation-rollback'], true)) {
            expect($holder->problem)->toBe(ProblemCode::ProviderUnavailable);
        }
        $empty = in_array($mode, ['revoke-rollback', 'generation-rollback'], true);
        expect($s->counts())->toBe(['memberships' => $empty ? 0 : 1, 'active' => $empty ? 0 : 1,
            'revision' => $empty ? 0 : ($mode === 'add-remove' ? 3 : 1), 'count' => $empty ? 0 : 1, 'counter_revision' => $empty ? 0 : ($mode === 'add-remove' ? 3 : 1),
            'receipts' => match ($mode) {
                'generation-rollback' => 0, 'different' => 2, 'add-remove' => 4, default => 1
            },
            'outbox' => $empty ? 0 : ($mode === 'add-remove' ? 3 : 1)]);
    } finally {
        $s->cleanup();
    }
})->with(['same', 'digest', 'different', 'add-remove', 'rollback', 'revoke-rollback', 'generation-rollback']);

it('recovers a lost response from the original key after an independent process commits', function (): void {
    $s = new ReactionScenario;
    try {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/Social/reaction-child.php', 'lost', 'lost'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start lost-response client');
        }
        $pid = trim((string) fgets($pipes[1]));
        $output = stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        expect(proc_close($process))->toBe(0, $error)->and((int) $pid)->toBeGreaterThan(0)->and($output)->toBe('');
        fwrite(STDERR, $error);
        $command = $s->command();
        $receipt = $s->reactions->lookup(handle: $s->handle, namespace: $command->namespace, key: $command->key);
        expect($receipt?->isSuccess())->toBeTrue()->and($s->execute($command))->toEqual($receipt);
        expect($s->counts())->toBe(['memberships' => 1, 'active' => 1, 'revision' => 1, 'count' => 1, 'counter_revision' => 1, 'receipts' => 1, 'outbox' => 1]);
    } finally {
        $s->cleanup();
    }
});
