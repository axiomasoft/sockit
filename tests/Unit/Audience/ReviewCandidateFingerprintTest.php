<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('captures complete recursive covers and rejects stale source in any covered file', function (): void {
    $process = new Process(command: ['python3', '-B', 'scripts/tests/test_review_candidate.py'], cwd: dirname(__DIR__, 3));
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
