<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Audience\Data\PrivacyFloor;
use SocKit\Audience\Enums\Scope;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\Panel;
use SocKit\Tests\Fixtures\Panels\ReceiptModel;
use SocKit\Tests\Unit\Audience\Support\Scenario;

it('materializes a detached immutable storage snapshot and closes its builder', function (): void {
    $settings = ['nested' => ['value' => 10]];
    $builder = (new Panel)->id('school.social')->hostTypes('article')->prefix('school_')->moduleSettings('demo.module', $settings);
    $snapshot = $builder->materialize();
    $settings['nested']['value'] = 20;
    expect($snapshot->id->value)->toBe('school.social')
        ->and($snapshot->storage->tables['receipts'])->toBe('school_receipts')
        ->and(Scenario::object($snapshot->moduleSettings['demo.module']['nested'])['value'])->toBe(10);
    expect(fn () => $builder->prefix('changed_'))->toThrow(InvalidPanelException::class);
});

it('strips nested PHP references from settings, table maps and privacy floors', function (): void {
    $number = 10;
    $table = 'custom_receipts';
    $level = 'school';
    $scope = Scope::Contour;
    $floor = new PrivacyFloor(allowedScopes: [Scope::Direct, &$scope],
        defaultScope: Scope::Direct, maxScope: $scope, minorDefaultScope: Scope::Direct,
        minorMaxScope: Scope::Direct, contourLevels: ['class', &$level]);
    $snapshot = (new Panel)->id('host.articles')->hostTypes('article')->floor($floor)->prefix('a_')
        ->tables(['receipts' => &$table])->moduleSettings('demo.module', ['nested' => ['value' => &$number]])->materialize();
    $number = 99;
    $table = 'foreign';
    $level = 'changed';
    $scope = Scope::Public;
    $nested = Scenario::object($snapshot->moduleSettings['demo.module']['nested']);
    expect($nested['value'])->toBe(10)->and($snapshot->storage->tables['receipts'])->toBe('a_custom_receipts')
        ->and($snapshot->floor->contourLevels)->toBe(['class', 'school'])
        ->and($snapshot->floor->allowedScopes)->toBe([Scope::Direct, Scope::Contour]);
});

it('validates declared identifiers at the length boundary and rejects sanitizing or truncation', function (): void {
    $table = str_repeat('a', 63);
    expect((new Panel)->id('host.articles')->hostTypes('article')->tables(['receipts' => $table])->materialize()->storage->tables['receipts'])->toBe($table);
    foreach (['a.b', 'a-b', 'Uppercase', str_repeat('a', 64), ''] as $invalid) {
        expect(fn () => (new Panel)->id('host.articles')->hostTypes('article')->tables(['receipts' => $invalid])->materialize())
            ->toThrow(InvalidPanelException::class);
    }
});

it('rejects unknown model keys and defers class loading and model compatibility to P3', function (): void {
    expect(fn () => (new Panel)->id('host.articles')->hostTypes('article')->models(['foreign' => ReceiptModel::class])->materialize())
        ->toThrow(InvalidPanelException::class);
    $loads = [];
    $loader = static function (string $class) use (&$loads): void {
        if (str_starts_with($class, 'Future\\')) {
            $loads[] = $class;
        }
    };
    spl_autoload_register($loader);
    try {
        $builder = (new Panel)->id('host.articles')->hostTypes('article');
        // Reflection supplies an intentionally unresolved generated class name.
        new ReflectionMethod($builder, 'models')->invoke($builder, ['receipts' => 'Future\\GeneratedReceipt']);
        expect($builder->materialize()->storage->models['receipts'])->toBe('Future\\GeneratedReceipt')
            ->and($loads)->toBe([]);
    } finally {
        spl_autoload_unregister($loader);
    }
});

it('rejects callback and object settings with panel/provider/key diagnostics', function (): void {
    foreach ([static fn (): bool => true, new stdClass] as $value) {
        $builder = new Panel(provider: 'HostPanel')->id('host.articles')->hostTypes('article');
        new ReflectionMethod($builder, 'moduleSettings')->invoke($builder, 'demo.module', ['nested' => [$value]]);
        try {
            $builder->materialize();
            Assert::fail('Invalid settings accepted');
        } catch (InvalidPanelException $error) {
            expect($error->problem()->value)->toBe('sockit.configuration_invalid')
                ->and($error->detail()['panel'])->toBe('host.articles')->and($error->detail()['provider'])->toBe('HostPanel')
                ->and($error->detail()['rule'])->toBe('PANEL_SETTING_INVALID');
        }
    }
});
