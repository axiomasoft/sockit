<?php

declare(strict_types=1);

use SocKit\Posts\Data\MediaReference;
use SocKit\Posts\Data\PostCommand;
use SocKit\Posts\Data\PostResult;
use SocKit\Posts\Data\PostView;
use SocKit\Posts\Data\SafetyVerdict;
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

it('keeps Posts on public owner contracts and exposes immutable DTOs without ORM or author authority', function (): void {
    $files = SourceScanner::php('packages/core/modules/posts/src');
    expect(Gates::internals($files))->toBe([])->and(Gates::dependencies($files))->toBe([])->and(Gates::signatures($files))->toBe([]);
    foreach ($files as $path) {
        expect((string) file_get_contents($path))->not->toContain('SocKit\\Reactions', 'SocKit\\Operations\\Internal', 'envelope_targets', 'host_targets', 'beginTransaction(', '->commit(', '->rollBack(', 'setDefaultConnection(', 'setTable(', 'setConnection(', 'Storage::', 'file_put_contents(');
    }
    foreach ([PostCommand::class, PostResult::class, PostView::class, MediaReference::class, SafetyVerdict::class] as $type) {
        $reflection = new ReflectionClass($type);
        expect($reflection->isReadOnly())->toBeTrue()->and($reflection->isFinal())->toBeTrue();
    }
    $command = new ReflectionClass(PostCommand::class);
    expect(array_map(fn (ReflectionProperty $property): string => $property->getName(), $command->getProperties()))->not->toContain('author', 'actor', 'installation');
    $mutation = (string) file_get_contents('packages/core/modules/posts/src/Internal/PostMutation.php');
    expect($mutation)->toContain('implements OwnerMutation', "where('revision'", 'affectedRows: $affected');
});
