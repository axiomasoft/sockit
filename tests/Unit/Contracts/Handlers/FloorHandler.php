<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use PHPUnit\Framework\Assert;
use SocKit\Audience\Exceptions\FloorWidensException;
use SocKit\Audience\Exceptions\InvalidPrivacyFloorException;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class FloorHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $input = Scenario::object($fixture['input']);
        $expect = Scenario::object($fixture['expect']);
        try {
            $floor = Scenario::floor(Scenario::object($input['floor']));
            if (isset($input['surface'])) {
                $surface = Scenario::floor(Scenario::object($input['surface']));
                expect($floor->narrowedBy($surface))->toBe($surface);
            }
            expect($expect['valid'])->toBeTrue();
        } catch (InvalidPrivacyFloorException|FloorWidensException $exception) {
            expect($expect['valid'])->toBeFalse()->and($exception->problem()->value)->toBe('sockit.configuration_invalid')->and($exception->detail()['rule'])->toBe($expect['rule']);

            return;
        }
        if ($expect['valid'] !== true) {
            Assert::fail('Invalid floor fixture did not throw');
        }
    }
}
