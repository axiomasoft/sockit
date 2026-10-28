<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A5 accepts the real core tree', function (): void {
    expect(Gates::naming(SourceScanner::php('packages/core/src')))->toBe([]);
});

it('A5 rejects its negative control', function (): void {
    $violations = Gates::naming(SourceScanner::fixture('violations/naming.php.txt'));
    expect($violations)->not->toBe([]);
    foreach ($violations as $violation) {
        expect($violation['gate'])->toBe('A5')
            ->and($violation['message'])->toStartWith('A5 ')
            ->and($violation['line'])->toBeGreaterThan(0);
    }
});

it('A5 rejects all forbidden suffixes and both missing Data modifiers', function (): void {
    expect(Gates::naming(SourceScanner::fixture('violations/naming.php.txt')))->toHaveCount(7);
    expect(Gates::naming(SourceScanner::fixture('valid/panels.php.txt')))->toBe([]);
});
