<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $srcPaths = array_values(array_filter(glob(__DIR__.'/packages/*/src') ?: [], 'is_dir'));
    $migrationPaths = array_values(array_filter(glob(__DIR__.'/packages/*/database/migrations/*') ?: [], 'is_dir'));
    $testPaths = array_values(array_filter(glob(__DIR__.'/packages/*/tests') ?: [], 'is_dir'));
    $rootTests = is_dir(__DIR__.'/tests') ? [__DIR__.'/tests'] : [];

    $rectorConfig->disableParallel();
    $rectorConfig->paths([...$srcPaths, ...$migrationPaths, ...$testPaths, ...$rootTests]);
    $rectorConfig->importNames(importDocBlockNames: false);
    $rectorConfig->removeUnusedImports();

    PackmanRectorSet::configure($rectorConfig, [
        ThrowWithPreviousExceptionRector::class,
    ]);
};
