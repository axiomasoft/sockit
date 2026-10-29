<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A4 accepts the real core tree', function (): void {
    expect(Gates::modules(SourceScanner::php('packages/core/src')))->toBe([]);
});

it('A4 rejects its negative control', function (): void {
    $violations = Gates::modules(SourceScanner::fixture('violations/unknown-module.php.txt'));
    expect($violations)->not->toBe([]);
    foreach ($violations as $violation) {
        expect($violation['gate'])->toBe('A4')
            ->and($violation['message'])->toStartWith('A4 ')
            ->and($violation['line'])->toBeGreaterThan(0);
    }
});

it('A4 explicitly allows Panels before runtime PanelId exists', function (): void {
    expect(Gates::modules(SourceScanner::fixture('valid/panels.php.txt')))->toBe([]);
});

it('A4 explicitly admits the schema compiler namespace while retaining the unknown namespace control', function (): void {
    $files = SourceScanner::php('packages/core/src/Schema');
    expect($files)->not->toBe([]);
    expect(Gates::modules($files))->toBe([])
        ->and(Gates::modules(SourceScanner::fixture('violations/unknown-module.php.txt')))->not->toBe([]);
});
