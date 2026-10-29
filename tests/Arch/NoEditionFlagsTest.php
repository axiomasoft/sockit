<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A10 accepts the real core tree', function (): void {
    expect(Gates::editions(SourceScanner::php('packages/core/src')))->toBe([]);
});

it('A10 rejects its negative control', function (): void {
    $violations = Gates::editions(SourceScanner::fixture('violations/editions.php.txt'));
    expect($violations)->not->toBe([]);
    foreach ($violations as $violation) {
        expect($violation['gate'])->toBe('A10')
            ->and($violation['message'])->toStartWith('A10 ')
            ->and($violation['line'])->toBeGreaterThan(0);
    }
});

it('A10 detects string keys, methods and properties but ignores comments', function (): void {
    expect(Gates::editions(SourceScanner::fixture('violations/editions.php.txt')))->toHaveCount(3)
        ->and(Gates::editions(SourceScanner::fixture('valid/identifiers.php.txt')))->toBe([]);
});
