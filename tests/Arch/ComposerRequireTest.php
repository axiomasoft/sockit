<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A7 accepts the real core require', function (): void {
    expect(Gates::composerRequire(['packages/core/composer.json']))->toBe([]);
});

it('A7 rejects each forbidden vendor', function (): void {
    $violations = Gates::composerRequire(SourceScanner::fixture('violations/composer-require.php.txt'));
    expect(array_column($violations, 'gate'))->toBe(['A7', 'A7', 'A7']);
});

it('A7 checks require rather than dev dependencies', function (): void {
    expect(Gates::composerRequire(SourceScanner::fixture('valid/composer.php.txt')))->toBe([]);
});
