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

it('F1 rejects inherited Internal self and parent in parameters and return types', function (): void {
    $files = SourceScanner::fixture('violations/relative-internal-signature.php.txt');
    expect(Gates::markers($files))->toBe([])
        ->and(Gates::internals($files))->toBe([])
        ->and(Gates::modules($files))->toBe([])
        ->and(Gates::ownership($files))->toBe([]);
    $violations = Gates::signatures($files);
    expect($violations)->toHaveCount(8);
    foreach ($violations as $violation) {
        expect($violation['gate'])->toBe('A9')
            ->and($violation['message'])->toEndWith(' exposes SocKit\\Support\\Internal\\RelativeBase');
    }
    $messages = implode("\n", array_column($violations, 'message'));
    expect($messages)->toContain('::selfReturn ', '::selfArgument ', '::nullableSelf ', '::unionSelf ', '::parentReturn ', '::parentArgument ');
    expect(str_contains($messages, '::safeStatic '))->toBeFalse();
});

it('F1 rejects self parent and static hiding Eloquent or HTTP base types', function (): void {
    $violations = Gates::signatures(SourceScanner::fixture('violations/relative-framework-signature.php.txt'));
    expect($violations)->toHaveCount(9);
    foreach (['RelativeRecord', 'RelativeRequest', 'RelativeResponse'] as $type) {
        foreach (['relativeSelf', 'relativeParent', 'relativeStatic'] as $method) {
            $matches = array_values(array_filter($violations, fn (array $violation): bool => str_contains($violation['message'], $type.'::'.$method.' exposes ')));
            expect($matches)->toHaveCount(1)->and($matches[0]['gate'])->toBe('A9');
        }
    }
});

it('F1 allows safe relative types traits and late binding to the public inheritor', function (): void {
    expect(Gates::signatures(SourceScanner::fixture('valid/relative-signature.php.txt')))->toBe([]);
});
