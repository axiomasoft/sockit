<?php

declare(strict_types=1);

namespace SocKit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SocKit\SocKitServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SocKitServiceProvider::class];
    }
}
