<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('keeps production reaction mutations on public kernel contracts without host SQL or sibling imports', function (): void {
    $files = SourceScanner::php('packages/core/modules/reactions/src');
    expect(Gates::internals($files))->toBe([])->and(Gates::dependencies($files))->toBe([])->and(Gates::signatures($files))->toBe([]);
    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        expect($source)->not->toContain('SocKit\\Posts', 'SocKit\\Operations\\Internal', 'envelope_targets', 'host_targets', 'beginTransaction(', '->commit(', '->rollBack(', 'setDefaultConnection(', 'setTable(', 'setConnection(');
    }
    $mutation = (string) file_get_contents('packages/core/modules/reactions/src/Internal/ReactionMutation.php');
    expect($mutation)->toContain('implements OwnerMutation', 'identity->verify(', "where('revision'", 'affectedRows: $affected');
});
