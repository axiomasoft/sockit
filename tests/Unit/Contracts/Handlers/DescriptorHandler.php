<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

use SocKit\Audience\Data\PrivacyDescriptor;
use SocKit\Tests\Unit\Audience\Support\Scenario;

final class DescriptorHandler implements FixtureHandler
{
    public function check(array $fixture): void
    {
        $wire = Scenario::object($fixture['input']);
        expect(PrivacyDescriptor::fromContractArray($wire)->toContractArray())->toBe($wire);
    }
}
