<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use PHPUnit\Framework\Assert;
use SocKit\Modules\Exceptions\InvalidModuleException;
use SocKit\Modules\Modules;
use SocKit\Panels\Exceptions\InvalidPanelException;
use SocKit\Panels\PanelDefinitions;
use SocKit\Surfaces\Contracts\SurfaceDefinitions;
use SocKit\Surfaces\Exceptions\InvalidSurfaceDefinitionException;
use SocKit\Tests\Fixtures\Surfaces\Scenario;
use SocKit\Tests\Unit\Audience\Support\Scenario as Wire;

final class SurfacesHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = Wire::object(json_decode(json_encode($fixture['input'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $expected = Wire::object($fixture['expect']);
        $app = Scenario::app($input);
        try {
            $app->boot();
        } catch (InvalidSurfaceDefinitionException $error) {
            expect($expected['valid'])->toBeFalse()->and($error->problem()->value)->toBe('sockit.configuration_invalid');
            $rules = array_map(static fn (string $line): string => explode(' ', $line)[0], $error->errors);
            sort($rules);
            expect($rules)->toBe($expected['rules']);
            foreach ($error->errors as $line) {
                expect($line)->toMatch('/^[A-Z_]+ \[SocKit\\\\Tests\\\\Fixtures\\\\Surfaces\\\\[^\]]+\] panel=.* key=.+$/');
            }
            expect(fn () => $app->make(Modules::class))->toThrow(InvalidModuleException::class)
                ->and(fn () => $app->make(PanelDefinitions::class))->toThrow(InvalidPanelException::class)
                ->and(fn () => $app->make(SurfaceDefinitions::class))->toThrow(InvalidSurfaceDefinitionException::class);

            return;
        }
        if ($expected['valid'] !== true) {
            Assert::fail('Invalid surface fixture booted');
        }
        $snapshot = [];
        foreach ($app->make(SurfaceDefinitions::class)->all() as $key => $surface) {
            $snapshot[$key] = ['panel' => $surface->panel->value, 'kind' => $surface->kind->value, 'host' => $surface->hostType,
                'features' => $surface->features, 'settings' => $surface->settings, 'contour' => $surface->contourLevel,
                'contour_levels' => $surface->effectiveFloor->contourLevels, 'archive_readers' => $surface->archiveReaders];
        }
        expect($snapshot)->toBe($expected['surfaces']);
    }
}
