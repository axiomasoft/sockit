<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A6 accepts the real core tree', function (): void {
    expect(Gates::tenancy(SourceScanner::php('packages/core/src')))->toBe([]);
});

it('A6 rejects its negative control', function (): void {
    $violations = Gates::tenancy(SourceScanner::fixture('violations/tenancy.php.txt'));
    expect($violations)->not->toBe([]);
    foreach ($violations as $violation) {
        expect($violation['gate'])->toBe('A6')
            ->and($violation['message'])->toStartWith('A6 ')
            ->and($violation['line'])->toBeGreaterThan(0);
    }
});

it('A6 detects keys and variables but ignores comments and prose', function (): void {
    expect(Gates::tenancy(SourceScanner::fixture('violations/tenancy.php.txt')))->toHaveCount(2)
        ->and(Gates::tenancy(SourceScanner::fixture('valid/identifiers.php.txt')))->toBe([]);
});
