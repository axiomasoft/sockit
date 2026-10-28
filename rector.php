<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/packages/core/src', __DIR__.'/packages/core/config', __DIR__.'/tests'])
    ->withPhpSets(php84: true);
