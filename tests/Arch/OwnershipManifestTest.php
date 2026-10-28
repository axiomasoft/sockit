<?php

declare(strict_types=1);

use SocKit\Panels\PanelStorage;
use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A8 accepts real namespace ownership', function (): void {
    expect(Gates::ownership(SourceScanner::php('packages/core/src')))->toBe([]);
});

it('A8 rejects overlapping prefixes even without a matching source type', function (): void {
    $manifest = SourceScanner::fixture('violations/ownership-overlap.php.txt')[0];
    $violations = Gates::ownership([], $manifest);
    expect(array_column($violations, 'gate'))->toBe(['A8']);
});

it('A8 rejects an uncovered namespace', function (): void {
    $manifest = SourceScanner::fixture('violations/ownership-uncovered.php.txt')[0];
    $violations = Gates::ownership(SourceScanner::fixture('valid/panels.php.txt'), $manifest);
    expect(array_column($violations, 'gate'))->toBe(['A8']);
});

it('A8 accepts separate non-overlapping owners', function (): void {
    expect(Gates::ownership(SourceScanner::fixture('valid/panels.php.txt'), SourceScanner::fixture('valid/composer.php.txt')[0]))->toBe([]);
});

it('declares the four kernel logical tables as core owned', function (): void {
    $manifest = json_decode((string) file_get_contents('packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(data_get($manifest, 'extra.sockit.owners.core.tables'))->toBe(PanelStorage::KERNEL_ENTITIES);
});
