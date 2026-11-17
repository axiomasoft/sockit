<?php

declare(strict_types=1);

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;

/** @return list<string> */
function sockitSourceFiles(string $subdir = 'packages/core/src'): array
{
    $root = dirname(__DIR__, 4).'/'.$subdir;
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** @return list<string> */
function collectTypeNames(?ReflectionType $type): array
{
    if (! $type instanceof ReflectionType) {
        return [];
    }

    if ($type instanceof ReflectionNamedType) {
        return [$type->getName()];
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        $names = [];
        foreach ($type->getTypes() as $inner) {
            foreach (collectTypeNames($inner) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    return [];
}

test('all public API/SPI symbols carry attributes, internal symbols never leak, and Eloquent models stay absent', function (): void {
    foreach (['packages/core/src', 'packages/testkit/src'] as $dir) {
        foreach (sockitSourceFiles($dir) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch) !== 1) {
                continue;
            }
            if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|enum|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $symMatch) !== 1) {
                continue;
            }

            /** @var class-string $fqcn */
            $fqcn = $nsMatch[1].'\\'.$symMatch[1];
            $ref = new ReflectionClass($fqcn);
            $isInternalNs = str_contains($fqcn, '\\Internal\\');
            $hasApiOrSpi = $ref->getAttributes(Api::class) !== [] || $ref->getAttributes(Spi::class) !== [];
            $hasInternalAttr = $ref->getAttributes(Internal::class) !== [];

            if ($isInternalNs) {
                expect($hasInternalAttr)->toBeTrue("Internal class {$fqcn} must carry #[Internal].")
                    ->and($hasApiOrSpi)->toBeFalse("Internal class {$fqcn} must not carry #[Api] or #[Spi].");

                continue;
            }

            if ($hasInternalAttr) {
                continue;
            }

            expect($hasApiOrSpi)->toBeTrue("Public symbol {$fqcn} must carry #[Api] or #[Spi].");

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $types = collectTypeNames($method->getReturnType());
                foreach ($method->getParameters() as $param) {
                    foreach (collectTypeNames($param->getType()) as $pt) {
                        $types[] = $pt;
                    }
                }

                foreach ($types as $typeName) {
                    expect($typeName)->not->toContain('\\Internal\\', "Public method {$fqcn}::{$method->getName()} leaks Internal type {$typeName}.");
                    expect($typeName)->not->toStartWith('Illuminate\\Database\\Eloquent\\', "Public method {$fqcn}::{$method->getName()} leaks Eloquent type {$typeName}.");
                }
            }
        }
    }
});

test('modules are strictly decoupled from sibling modules and contain zero comments, corex, or tenant_id leaks', function (): void {
    foreach (sockitSourceFiles('packages/core/src/Modules/Reactions') as $file) {
        $src = (string) file_get_contents($file);
        expect($src)->not->toContain('SocKit\\Modules\\Posts\\')
            ->and($src)->not->toContain('SocKit\\Modules\\Groups\\');
    }

    foreach (sockitSourceFiles('packages/core/src/Modules/Posts') as $file) {
        $src = (string) file_get_contents($file);
        expect($src)->not->toContain('SocKit\\Modules\\Reactions\\')
            ->and($src)->not->toContain('SocKit\\Modules\\Groups\\');
    }

    foreach (sockitSourceFiles('packages/core/src/Modules/Groups') as $file) {
        $src = (string) file_get_contents($file);
        expect($src)->not->toContain('SocKit\\Modules\\Reactions\\')
            ->and($src)->not->toContain('SocKit\\Modules\\Posts\\');
    }

    foreach (['packages/core/src', 'packages/core/database', 'packages/testkit/src'] as $dir) {
        foreach (sockitSourceFiles($dir) as $file) {
            $src = strtolower((string) file_get_contents($file));
            expect($src)->not->toContain('corex')
                ->and($src)->not->toContain('tenant_id')
                ->and($src)->not->toContain('commentsplugin')
                ->and($src)->not->toContain('commentservice')
                ->and($src)->not->toContain('sockit_comments')
                ->and($src)->not->toContain('create(\'comments\'');
        }
    }
});
