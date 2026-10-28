<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A9 accepts the real core tree', function (): void {
    expect(Gates::signatures(SourceScanner::php('packages/core/src')))->toBe([]);
});

it('A9 rejects its negative control', function (): void {
    $violations = Gates::signatures(SourceScanner::fixture('violations/public-signature.php.txt'));
    expect($violations)->not->toBe([]);
    foreach ($violations as $violation) {
        expect($violation['gate'])->toBe('A9')
            ->and($violation['message'])->toStartWith('A9 ')
            ->and($violation['line'])->toBeGreaterThan(0);
    }
});

it('A9 reflects aliases, nullable, union, intersection, DNF, SPI, traits and hidden base types', function (): void {
    $violations = Gates::signatures(SourceScanner::fixture('violations/public-signature.php.txt'));
    expect($violations)->toHaveCount(11);
    expect(implode("\n", array_column($violations, 'message')))
        ->toContain('::intersection exposes Closure', '::dnf exposes Closure', '::leak exposes SocKit\\Support\\Internal\\Hidden', 'HiddenBase::handle', 'BadPort::handle', 'BadTrait::callback');
});

it('A9 accepts safe signatures, private and protected implementation details and empty input', function (): void {
    expect(Gates::signatures(SourceScanner::fixture('valid/public-signature.php.txt')))->toBe([])
        ->and(Gates::signatures([]))->toBe([]);
});
