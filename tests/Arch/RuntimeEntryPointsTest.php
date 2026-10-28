<?php

declare(strict_types=1);

use SocKit\Operations\Contracts\OwnerMutation;
use SocKit\Operations\Data\CommandEnvelope;
use SocKit\Operations\Data\MutationEffect;
use SocKit\Operations\Data\MutationEvent;
use SocKit\Operations\Data\MutationRequest;
use SocKit\Operations\Data\MutationSnapshot;
use SocKit\Operations\Operations;
use SocKit\Surfaces\SurfaceInstances;
use SocKit\Tests\Arch\Support\Gates;
use SocKit\Tests\Arch\Support\SourceScanner;

it('keeps owner mutation entry typed and never accepts payload author or installation as command authority', function (): void {
    $command = new ReflectionClass(CommandEnvelope::class);
    expect(array_map(fn (ReflectionParameter $p): string => $p->getName(), $command->getConstructor()?->getParameters() ?? []))->toBe(['key', 'namespace', 'digest', 'expectedRevision']);
    $execute = new ReflectionMethod(objectOrMethod: Operations::class, method: 'execute');
    expect(array_map(fn (ReflectionParameter $p): string => $p->getName(), $execute->getParameters()))->toBe(['handle', 'command', 'mutation'])
        ->and((string) $execute->getParameters()[2]->getType())->toBe(OwnerMutation::class);
    foreach ([MutationRequest::class, MutationSnapshot::class, MutationEffect::class, MutationEvent::class] as $dto) {
        $reflection = new ReflectionClass($dto);
        expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
    }
});

it('keeps public lifecycle and operation facades free of persistence and routes writes through the owning envelope', function (): void {
    foreach ([Operations::class, SurfaceInstances::class] as $type) {
        $path = new ReflectionClass($type)->getFileName();
        if ($path === false) {
            throw new RuntimeException('Missing source');
        }
        $source = file_get_contents($path);
        expect($source)->not->toContain('->table(', '->commit(', '->beginTransaction(', '->setDefaultConnection(', 'config(', 'static $');
    }
    $files = SourceScanner::php('packages/core/src');
    expect(Gates::signatures($files))->toBe([])->and(Gates::internals($files))->toBe([]);
    // A module owner cannot be invoked from another public shortcut around the fixed kernel protocol.
    foreach ($files as $file) {
        if (str_contains($file, '/Internal/Envelope/')) {
            continue;
        }
        expect((string) file_get_contents($file))->not->toContain('$mutation->persist(', '$mutation->lock(');
    }
});
