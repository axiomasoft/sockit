<?php

declare(strict_types=1);

it('reports all documentation violations and accepts the actual documentation', function (): void {
    $script = escapeshellarg(dirname(__DIR__, 3).'/scripts/check-docs.php');
    $fixture = escapeshellarg(dirname(__DIR__, 3).'/tests/Fixtures/docs-violations');
    exec(PHP_BINARY." $script --root=$fixture 2>&1", $output, $exit);
    expect($exit)->toBe(1)
        ->and(implode("\n", $output))->toContain('ADR section Альтернативы', 'Forbidden filesystem reference', 'T-31', 'Матрица решений', 'Генерация и upgrade', 'Условные адаптеры', 'Предложение канону');

    exec(PHP_BINARY." $script 2>&1", $actual, $actualExit);
    expect($actualExit)->toBe(0)->and(implode("\n", $actual))->toBe('OK');
});

it('requires complete S and SN identifiers', function (): void {
    $root = sys_get_temp_dir().'/sockit-docs-'.bin2hex(random_bytes(8));
    mkdir($root.'/docs/architecture', 0777, true);
    $path = $root.'/docs/architecture/traceability.md';
    file_put_contents($path, "# Trace\n\nS-010 SN-010\n");
    try {
        $script = escapeshellarg(dirname(__DIR__, 3).'/scripts/check-docs.php');
        exec(PHP_BINARY.' '.$script.' --root='.escapeshellarg($root).' 2>&1', $output, $exit);
        expect($exit)->toBe(1)
            ->and(implode("\n", $output))->toContain('Missing traceability requirement: S-01', 'Missing traceability requirement: SN-01', 'Missing traceability requirement: SN-22');
    } finally {
        unlink($path);
        rmdir($root.'/docs/architecture');
        rmdir($root.'/docs');
        rmdir($root);
    }
});
