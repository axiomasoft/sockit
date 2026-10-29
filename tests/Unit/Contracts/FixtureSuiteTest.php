<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use SocKit\Tests\Unit\Contracts\Handlers\FixtureHandler;

it('discovers and checks every wire fixture', function (): void {
    $root = dirname(__DIR__, 3).'/packages/core/resources/contracts/fixtures';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $count = 0;
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'json') {
            continue;
        }
        $count++;
        $fixture = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($fixture) || ! isset($fixture['contract']) || ! is_string($fixture['contract']) || $fixture['contract'] === '') {
            throw new RuntimeException('Invalid fixture contract');
        }
        $normalized = [];
        foreach ($fixture as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Invalid fixture key');
            }
            $normalized[$key] = $value;
        }
        expect($fixture)->toHaveKeys(['fixture', 'contract', 'version', 'input', 'expect']);
        $handler = 'SocKit\\Tests\\Unit\\Contracts\\Handlers\\'.Str::studly($fixture['contract']).'Handler';
        expect(class_exists($handler))->toBeTrue("Missing fixture handler: $handler");
        $instance = new $handler;
        if (! $instance instanceof FixtureHandler) {
            throw new RuntimeException("Invalid fixture handler: $handler");
        }
        $instance->check($normalized);
    }
    expect($count)->toBeGreaterThan(0);
});
