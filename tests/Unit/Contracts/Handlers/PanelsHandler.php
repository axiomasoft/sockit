<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use PHPUnit\Framework\Assert;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\Modules;
use SocKit\Panels\Data\PanelId;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\PanelDefinitions;
use SocKit\Tests\Fixtures\Panels\ArticlePanel;
use SocKit\Tests\Fixtures\Panels\ConfiguredPanel;
use SocKit\Tests\Fixtures\Panels\Scenario;
use SocKit\Tests\Unit\Audience\Support\Scenario as Wire;

final class PanelsHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = Wire::object(json_decode(json_encode($fixture['input'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $expected = Wire::object($fixture['expect']);
        $app = Scenario::app($input);
        try {
            $app->boot();
        } catch (InvalidPanelException $error) {
            expect($expected['valid'])->toBeFalse()
                ->and($error->problem()->value)->toBe('sockit.configuration_invalid')
                ->and($error->detail()['rule'])->toBe($expected['rule'])
                ->and($error->detail())->toHaveKeys(['panel', 'provider', 'key']);
            expect(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class)
                ->and(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class);

            return;
        }
        if ($expected['valid'] !== true) {
            Assert::fail('Invalid panel fixture booted successfully');
        }
        $registry = $app->make(PanelDefinitions::class);
        $snapshot = [];
        foreach ($registry->all() as $id => $panel) {
            $snapshot[$id] = ['hosts' => $panel->hostTypes, 'tables' => $panel->storage->tables, 'modules' => $panel->modules, 'settings' => $panel->moduleSettings];
        }
        expect($snapshot)->toBe($expected['panels']);
        $copy = $registry->all();
        $first = array_key_first($copy);
        assert($first !== null);
        unset($copy[$first]);
        expect($registry->has(new PanelId($first)))->toBeTrue();
        if (($input['late_registration'] ?? false) === true) {
            expect(fn () => PanelDefinitions::add($app, ArticlePanel::class))->toThrow(InvalidPanelException::class);
        }
        $retained = $app->make(ConfiguredPanel::class)->builder;
        assert($retained !== null);
        expect(fn () => $retained->prefix('late_'))->toThrow(InvalidPanelException::class);
    }
}
