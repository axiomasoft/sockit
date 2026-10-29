<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use SocKit\Configuration\Data\ModulesConfig;
use SocKit\Configuration\SocKitConfig;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Exceptions\InvalidSurfaceDefinitionException;
use SocKit\Tests\Fixtures\Modules\DemoModule;
use SocKit\Tests\Fixtures\Panels\ConfiguredPanel;
use SocKit\Tests\Fixtures\Surfaces\ReferencedSettingsModule;
use SocKit\Tests\Fixtures\Surfaces\Scenario;

it('F1 detaches nested default and global references before merge and registry publication', function (bool $override): void {
    $app = Scenario::app();
    $value = 20;
    $app->make('config')->set('sockit.modules.enabled', ['social.posts', 'social.reactions', 'review.module']);
    if ($override) {
        $app->make('config')->set('sockit.modules.settings', ['review.module' => ['policy' => ['limit' => &$value]]]);
    }
    Modules::add($app, ReferencedSettingsModule::class);
    $app->instance(ConfiguredPanel::class, new ConfiguredPanel(['id' => 'review.settings', 'prefix' => 'review_', 'modules' => ['review.module']]));
    PanelDefinitions::add($app, ConfiguredPanel::class);
    $app->boot();
    $modules = $app->make(Modules::class);
    $expected = ['policy' => ['limit' => $override ? 20 : 10, 'mirror' => 10, 'limits' => [10]]];
    expect($modules->settings()['review.module'])->toBe($expected);
    $copy = $modules->settings();
    if (! is_array($copy['review.module']['policy']) || ! is_array($copy['review.module']['policy']['limits'])) {
        Assert::fail('Expected nested policy and limits list');
    }
    $copy['review.module']['policy']['limit'] = 999;
    $copy['review.module']['policy']['limits'][0] = 998;
    $value = 997;
    expect($modules->settings()['review.module'])->toBe($expected)
        ->and($app->make(PanelDefinitions::class)->get(new PanelId('review.settings'))->moduleSettings['review.module'])->toBe($expected);
    if ($override) {
        expect($app->make(SocKitConfig::class)->modules->settings['review.module']['policy'])->toBe(['limit' => 20]);
    }
})->with([false, true]);

it('F1 detaches aliases throughout normalized nested maps and lists in both directions', function (): void {
    $limit = 10;
    $nested = ['limit' => &$limit];
    $input = ['policy' => &$nested, 'list' => [&$nested, &$limit, null, false, 1.5]];
    $normalized = ModulesConfig::settingsMap(value: $input, key: 'settings');
    $expected = ['policy' => ['limit' => 10], 'list' => [['limit' => 10], 10, null, false, 1.5]];
    $limit = 999;
    $nested['extra'] = true;
    expect($normalized)->toBe($expected);
    $copy = $normalized;
    if (! is_array($copy['list']) || ! is_array($copy['list'][0]) || ! is_array($copy['policy'])) {
        Assert::fail('Expected nested map and list values');
    }
    $copy['list'][0]['limit'] = 998;
    $copy['policy']['limit'] = 997;
    expect($normalized)->toBe($expected);
});

it('F2 rejects every unknown settings module ID with its full path before any registry publication', function (): void {
    $app = Scenario::app();
    $app->make('config')->set('sockit.modules.enabled', ['social.posts', 'social.reactions', 'demo.module']);
    $app->make('config')->set('sockit.modules.settings', ['demo.modul' => ['limit' => 20, 'unknown' => 'secret-ignored'], 'missing.module' => []]);
    Modules::add($app, DemoModule::class);
    try {
        $app->boot();
        Assert::fail('Unknown module settings were ignored');
    } catch (InvalidModuleException $error) {
        expect($error->errors)->toBe([
            ['module' => 'demo.modul', 'key' => 'sockit.modules.settings.demo.modul', 'rule' => 'MODULE_SETTINGS_NOT_OFFERED'],
            ['module' => 'missing.module', 'key' => 'sockit.modules.settings.missing.module', 'rule' => 'MODULE_SETTINGS_NOT_OFFERED'],
        ])->and(json_encode($error->errors, JSON_THROW_ON_ERROR))->not->toContain('secret-ignored');
    }
    expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class)
        ->and(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class)
        ->and(fn () => $app->make(SurfaceDefinitions::class))->toThrow(InvalidSurfaceDefinitionException::class);
});

it('F2 retains settings for an offered disabled module without invoking its schema', function (): void {
    $app = Scenario::app();
    Modules::add($app, DemoModule::class);
    $app->make('config')->set('sockit.modules.settings', ['demo.module' => ['limit' => 20]]);
    $app->boot();
    expect($app->make(Modules::class)->manifests())->not->toHaveKey('demo.module')
        ->and($app->make(Modules::class)->settings())->not->toHaveKey('demo.module');
});
