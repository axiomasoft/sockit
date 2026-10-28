<?php

declare(strict_types=1);

namespace SocKit\Tests\Unit\Contracts\Handlers;

interface FixtureHandler
{
    /** @param array<string, mixed> $fixture */
    public function check(array $fixture): void;
}
