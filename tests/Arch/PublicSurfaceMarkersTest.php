<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('A1 accepts the real core tree', function (): void {
    expect(Gates::markers(SourceScanner::php('packages/core/src')))->toBe([]);
});

it('A1 rejects its negative control', function (): void {
    $violations = Gates::markers(SourceScanner::fixture('violations/missing-marker.php.txt'));
    expect($violations)->not->toBe([]);
    foreach ($violations as $violation) {
        expect($violation['gate'])->toBe('A1')
            ->and($violation['message'])->toStartWith('A1 ')
            ->and($violation['line'])->toBeGreaterThan(0);
    }
});

it('A1 resolves aliases, groups and fully qualified attributes on all named type kinds', function (): void {
    expect(Gates::markers(SourceScanner::fixture('valid/markers.php.txt')))->toBe([]);
});

it('A1 rejects Internal markers, double markers and unrelated attributes', function (string $fixture): void {
    $violations = Gates::markers(SourceScanner::fixture('violations/'.$fixture.'.php.txt'));
    expect(array_column($violations, 'gate'))->toBe(['A1']);
})->with(['internal-marker', 'double-marker', 'foreign-marker']);

it('A1 does not carry member attributes onto the next class', function (): void {
    $violations = Gates::markers(SourceScanner::fixture('valid/decorated-members.php.txt'));
    expect($violations)->toHaveCount(1)->and($violations[0]['message'])->toContain('MissingAfterMember');
});
