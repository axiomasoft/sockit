<?php

declare(strict_types=1);

use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('includes all social roots in every source architecture gate', function (): void {
    $files = [...SourceScanner::php('packages/core/modules/reactions/src'), ...SourceScanner::php('packages/core/modules/posts/src'), ...SourceScanner::php('packages/core/modules/groups/src')];
    expect($files)->not->toBe([]);
    foreach (['markers', 'dependencies', 'internals', 'modules', 'naming', 'tenancy', 'editions', 'ownership', 'signatures'] as $gate) {
        expect(Gates::$gate($files))->toBe([]);
    }
    foreach ($files as $file) {
        expect((string) file_get_contents($file))->not->toContain('$mutation->persist(', '$mutation->lock(', 'setDefaultConnection(', 'tenant_id');
    }
});

it('retains kernel social prohibition and rejects sibling and foreign Internal dependencies', function (): void {
    // The path is the production source root; changing namespace cannot grant another owner.
    foreach (['reactions' => 'Reactions', 'posts' => 'Posts', 'groups' => 'Groups'] as $module => $namespace) {
        $path = 'packages/core/modules/'.$module.'/src/Internal/ArchitectureControl'.bin2hex(random_bytes(8)).'.php';
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new RuntimeException('Cannot exclusively create architecture control');
        }
        fclose($file);
        try {
            file_put_contents($path, '<?php namespace SocKit\\'.$namespace.'\\Internal; use SocKit\\'.($module === 'posts' ? 'Reactions' : 'Posts').'\\Internal\\Other; use SocKit\\Schema\\Internal\\KernelSchema; final class ArchitectureControl {}');
            expect(Gates::dependencies([$path]))->not->toBe([])
                ->and(Gates::internals([$path]))->toHaveCount(2);
            file_put_contents($path, '<?php namespace SocKit\\Unknown; final class ArchitectureControl {}');
            expect(Gates::modules([$path]))->not->toBe([])->and(Gates::ownership([$path]))->not->toBe([]);
        } finally {
            unlink($path);
        }
    }
    expect(Gates::dependencies(SourceScanner::fixture('violations/dependencies.php.txt')))->toHaveCount(15);
    expect(Gates::modules(SourceScanner::fixture('violations/unknown-module.php.txt')))->not->toBe([]);
});
