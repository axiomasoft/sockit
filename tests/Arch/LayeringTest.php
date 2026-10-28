<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A2 and A3 accept the real core tree', function (): void {
    $files = SourceScanner::php('packages/core/src');
    expect(Gates::dependencies($files))->toBe([])
        ->and(Gates::internals($files))->toBe([]);
});

it('A2 detects every forbidden family, alias, group use and direct reference', function (): void {
    $violations = Gates::dependencies(SourceScanner::fixture('violations/dependencies.php.txt'));
    expect($violations)->toHaveCount(15);
    foreach ($violations as $violation) {
        expect($violation['gate'])->toBe('A2')->and($violation['message'])->toStartWith('A2 ');
    }
});

it('A3 rejects foreign Internal imports and fully qualified references', function (): void {
    $violations = Gates::internals(SourceScanner::fixture('violations/foreign-internal.php.txt'));
    expect(array_column($violations, 'gate'))->toBe(['A3', 'A3']);
});

it('A2 and A3 accept same module Internal, trait use and closure captures', function (): void {
    $files = SourceScanner::fixture('valid/references.php.txt');
    expect(Gates::dependencies($files))->toBe([])->and(Gates::internals($files))->toBe([]);
});
