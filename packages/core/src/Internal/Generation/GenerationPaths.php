<?php

declare(strict_types=1);

namespace SocKit\Internal\Generation;

use Composer\Autoload\ClassLoader;
use Packman\Attributes\Internal;
use PhpToken;

#[Internal]
final class GenerationPaths
{
    /** Reject symlinks (including dangling ones), aliases on case-insensitive hosts, and escapes.
     * @return string Absolute path */
    public static function check(string $root, string $relative, bool $vacant = true): string
    {
        GenerationFailure::require(valid: preg_match('~^[A-Za-z0-9_./-]+$~D', $relative) === 1 && ! str_starts_with($relative, '/') && ! in_array('..', explode('/', $relative), true) && ! in_array('.', explode('/', $relative), true), rule: 'GENERATION_PATH_INVALID');
        $path = $root;
        $parts = explode('/', $relative);
        foreach ($parts as $index => $part) {
            GenerationFailure::require(valid: $part !== '', rule: 'GENERATION_PATH_INVALID');
            if (is_dir($path)) {
                $entries = scandir($path);
                GenerationFailure::require(valid: $entries !== false, rule: 'GENERATION_PATH_UNREADABLE');
                foreach ($entries as $entry) {
                    GenerationFailure::require(valid: strtolower($entry) !== strtolower($part) || $entry === $part, rule: 'GENERATION_PATH_CASE_COLLISION');
                }
            }
            $path .= '/'.$part;
            clearstatcache(clear_realpath_cache: true, filename: $path);
            GenerationFailure::require(valid: ! is_link($path), rule: 'GENERATION_PATH_SYMLINK');
            if (file_exists($path)) {
                GenerationFailure::require(valid: $index < count($parts) - 1 || ! $vacant, rule: 'GENERATION_FILE_EXISTS');
                GenerationFailure::require(valid: is_dir($path) && realpath($path) === $path, rule: 'GENERATION_PATH_INVALID');
            }
        }

        return $path;
    }

    /** @return array{namespace:string, directory:string} */
    public static function mapping(string $root): array
    {
        $data = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $autoload = GenerationFailure::map(GenerationFailure::map($data)['autoload'] ?? null);
        $map = $autoload['psr-4'] ?? null;
        GenerationFailure::require(valid: is_array($map) && count($map) === 1, rule: 'GENERATION_PSR4_AMBIGUOUS: provide one application PSR-4 namespace and directory');
        $namespace = array_key_first($map);
        $directory = $map[$namespace];
        GenerationFailure::require(valid: is_string($namespace) && preg_match('~^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$~D', $namespace) === 1 && is_string($directory), rule: 'GENERATION_PSR4_AMBIGUOUS');
        $directory = rtrim($directory, '/');
        self::check(root: $root, relative: $directory, vacant: false);

        return ['namespace' => $namespace, 'directory' => $directory];
    }

    /** Check declarations even when their filename is not PSR-4 compliant.
     * @param list<string> $classes */
    public static function classes(string $root, string $directory, array $classes): void
    {
        $claimed = [];
        foreach ($classes as $class) {
            GenerationFailure::require(valid: ! isset($claimed[strtolower($class)]), rule: 'GENERATION_FQCN_COLLISION');
            $claimed[strtolower($class)] = true;
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                GenerationFailure::require(valid: $loader->findFile($class) === false, rule: 'GENERATION_FQCN_EXISTS');
                foreach (array_keys($loader->getClassMap()) as $existing) {
                    GenerationFailure::require(valid: strtolower($existing) !== strtolower($class), rule: 'GENERATION_FQCN_EXISTS');
                }
            }
        }
        foreach ([...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()] as $class) {
            GenerationFailure::require(valid: ! isset($claimed[strtolower($class)]), rule: 'GENERATION_FQCN_EXISTS');
        }
        if (! is_dir($root.'/'.$directory)) {
            return;
        }
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            GenerationFailure::require(valid: $file instanceof \SplFileInfo && ! $file->isLink(), rule: 'GENERATION_PATH_SYMLINK');
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                foreach (self::declarations((string) file_get_contents($file->getPathname())) as $class) {
                    GenerationFailure::require(valid: ! isset($claimed[strtolower($class)]), rule: 'GENERATION_FQCN_EXISTS');
                }
            }
        }
    }

    /** @return list<string> */
    private static function declarations(string $source): array
    {
        $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
        $namespace = '';
        $classes = [];
        foreach ($tokens as $index => $token) {
            if ($token->id === T_NAMESPACE) {
                $namespace = '';
                for ($next = $index + 1; isset($tokens[$next]) && ! $tokens[$next]->is([';', '{']); $next++) {
                    if ($tokens[$next]->is([T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
                        $namespace .= $tokens[$next]->text;
                    }
                }
            }
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                for ($next = $index + 1; isset($tokens[$next]) && $tokens[$next]->isIgnorable(); $next++) {
                    // Skip comments and whitespace, including between class and its name.
                }
                if (isset($tokens[$next]) && $tokens[$next]->id === T_STRING) {
                    $classes[] = ltrim($namespace.'\\'.$tokens[$next]->text, '\\');
                }
            }
        }

        return $classes;
    }
}
