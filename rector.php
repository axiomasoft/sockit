<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/packages/core/src', __DIR__.'/packages/core/config', __DIR__.'/packages/core/modules/reactions/src', __DIR__.'/packages/core/modules/posts/src', __DIR__.'/packages/core/modules/groups/src', __DIR__.'/tests'])
    ->withPhpSets(php84: true);
