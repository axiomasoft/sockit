<?php

declare(strict_types=1);

it('reports all documentation violations and accepts the actual documentation', function (): void {
    $script = escapeshellarg(dirname(__DIR__, 3).'/scripts/check-docs.php');
    $fixture = escapeshellarg(dirname(__DIR__, 3).'/tests/Fixtures/docs-violations');
    exec(PHP_BINARY." $script --root=$fixture 2>&1", $output, $exit);
    expect($exit)->toBe(1)
        ->and(implode("\n", $output))->toContain('ADR section Альтернативы', 'Forbidden filesystem reference', 'T-31');

    exec(PHP_BINARY." $script 2>&1", $actual, $actualExit);
    expect($actualExit)->toBe(0)->and(implode("\n", $actual))->toBe('OK');
});
