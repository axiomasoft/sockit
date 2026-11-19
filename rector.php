<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/packages/core/src', __DIR__.'/packages/core/config', __DIR__.'/packages/reactions/src', __DIR__.'/packages/posts/src', __DIR__.'/packages/groups/src', __DIR__.'/packages/testkit', __DIR__.'/tests'])
    ->withPhpSets(php84: true);
