<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('F2 preserves namespace and imports after literal braces in interpolated strings', function (): void {
    $files = SourceScanner::fixture('valid/interpolated-braces.php.txt');
    $source = SourceScanner::scan($files[0]);
    expect(array_column($source['types'], 'name'))->toBe([
        'SocKit\\Support\\InterpolatedBraces',
        'SocKit\\Support\\AfterInterpolatedBraces',
        'SocKit\\Support\\AfterStringImport',
        'SocKit\\Panels\\Data\\AfterInterpolatedNamespace',
    ])->and(Gates::markers($files))->toBe([])
        ->and(Gates::modules($files))->toBe([])
        ->and(Gates::ownership($files))->toBe([]);
});
