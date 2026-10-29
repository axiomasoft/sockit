<?php

declare(strict_types=1);

namespace SocKit\Internal\Generation;

/** Cooperative root lock + atomic link(2) no-replace; never rename over a host file. */
final readonly class ExclusiveWriter
{
    /** @param (\Closure(string, string): void)|null $observe Internal failure/race injection seam. */
    public function __construct(private ?\Closure $observe = null) {}

    public function install(GenerationPlan $plan): void
    {
        $lock = @fopen($plan->root, 'r');
        GenerationFailure::require(valid: $lock !== false, rule: 'GENERATION_LOCK_FAILED');
        $stage = '.sockit-stage-'.bin2hex(random_bytes(16));
        $owned = $directories = $staged = [];
        $failed = false;
        $recovery = [];
        try {
            GenerationFailure::require(valid: flock($lock, LOCK_EX), rule: 'GENERATION_LOCK_FAILED');
            foreach (array_keys($plan->sources) as $path) {
                GenerationPaths::check(root: $plan->root, relative: $path);
            }
            $this->directory(root: $plan->root, relative: $stage, directories: $staged);
            foreach ($plan->sources as $path => $source) {
                $temporary = $stage.'/'.hash('sha256', $path).'.php';
                $absolute = GenerationPaths::check(root: $plan->root, relative: $temporary);
                $stream = @fopen($absolute, 'x');
                GenerationFailure::require(valid: $stream !== false, rule: 'GENERATION_STAGE_FAILED');
                try {
                    GenerationFailure::require(valid: fwrite($stream, $source) === strlen($source) && fflush($stream), rule: 'GENERATION_STAGE_FAILED');
                } finally {
                    fclose($stream);
                    $staged[$temporary] = self::identity($absolute);
                }
                // Parse the actual staged bytes without executing consumer code or writing caches.
                \PhpToken::tokenize((string) file_get_contents($absolute), TOKEN_PARSE);
                ($this->observe)?->__invoke('staged', $path);
            }
            foreach ($plan->sources as $path => $source) {
                $this->directory(root: $plan->root, relative: dirname($path), directories: $directories);
                $absolute = GenerationPaths::check(root: $plan->root, relative: $path);
                ($this->observe)?->__invoke('before_install', $path);
                // Recheck after the seam; link itself still arbitrates a competing final-file create.
                GenerationPaths::check(root: $plan->root, relative: $path);
                $temporary = $stage.'/'.hash('sha256', $path).'.php';
                GenerationFailure::require(valid: self::identity($plan->root.'/'.$temporary) === $staged[$temporary], rule: 'GENERATION_STAGE_CHANGED');
                // Pin the destination directory as the process cwd while linking by basename.
                // A later parent symlink replacement cannot redirect the write outside the root.
                $this->inDirectory(dirname($absolute), function () use ($plan, $temporary, $path, $absolute): void {
                    ($this->observe)?->__invoke('before_link', $path);
                    GenerationPaths::check(root: $plan->root, relative: dirname($path), vacant: false);
                    GenerationFailure::require(valid: @link($plan->root.'/'.$temporary, basename($absolute)), rule: 'GENERATION_EXCLUSIVE_INSTALL_FAILED');
                });
                $owned[$path] = $staged[$temporary];
                ($this->observe)?->__invoke('installed', $path);
            }
        } catch (\Throwable) {
            $failed = true;
            foreach (array_reverse($owned, true) as $path => $identity) {
                if (! $this->remove(root: $plan->root, path: $path, identity: $identity)) {
                    $recovery[] = $path;
                }
            }
            foreach (array_reverse($directories, true) as $path => $identity) {
                if (! $this->remove(root: $plan->root, path: $path, identity: $identity, directory: true)) {
                    $recovery[] = $path;
                }
            }
        } finally {
            foreach (array_reverse($staged, true) as $path => $identity) {
                if (! $this->remove(root: $plan->root, path: $path, identity: $identity, directory: $path === $stage)) {
                    $recovery[] = $path;
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($failed || $recovery !== []) {
            if (! $failed) {
                $recovery = [...array_keys($owned), ...$recovery];
            }
            throw new GenerationFailure(rule: 'GENERATION_WRITE_FAILED', recovery: array_values(array_unique($recovery)));
        }
    }

    /** @param array<string, array{dev:int, ino:int, digest:string|null}> $directories */
    private function directory(string $root, string $relative, array &$directories): void
    {
        $parts = explode('/', $relative);
        $path = '';
        foreach ($parts as $part) {
            $path = $path === '' ? $part : $path.'/'.$part;
            $absolute = GenerationPaths::check(root: $root, relative: $path, vacant: false);
            if (! is_dir($absolute)) {
                GenerationFailure::require(valid: @mkdir($absolute, 0755), rule: 'GENERATION_DIRECTORY_FAILED');
                $directories[$path] = self::identity($absolute);
            }
        }
    }

    /** @return array{dev:int, ino:int, digest:string|null} */
    private static function identity(string $path): array
    {
        clearstatcache(clear_realpath_cache: true, filename: $path);
        $stat = lstat($path);
        GenerationFailure::require(valid: $stat !== false && ! is_link($path), rule: 'GENERATION_IDENTITY_FAILED');

        return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'digest' => is_file($path) ? (string) hash_file('sha256', $path) : null];
    }

    /** @param array{dev:int, ino:int, digest:string|null} $identity */
    private function remove(string $root, string $path, array $identity, bool $directory = false): bool
    {
        try {
            // Validate every parent before touching the leaf, which may have become user-owned.
            GenerationPaths::check(root: $root, relative: dirname($path) === '.' ? $path : dirname($path), vacant: false);
            $absolute = $root.'/'.$path;
            if (! file_exists($absolute) && ! is_link($absolute)) {
                return true;
            }
            if (is_link($absolute) || self::identity($absolute) !== $identity) {
                return false;
            }

            return $this->inDirectory(dirname($absolute), static function () use ($absolute, $identity, $directory): bool {
                $leaf = basename($absolute);
                if (self::identity($leaf) !== $identity) {
                    return false;
                }

                return $directory ? @rmdir($leaf) : @unlink($leaf);
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /** Console-only synchronous filesystem operation; always restore the caller's cwd.
     * @template T
     *
     * @param \Closure(): T $operation
     * @return T */
    private function inDirectory(string $directory, \Closure $operation): mixed
    {
        $previous = getcwd();
        GenerationFailure::require(valid: $previous !== false && @chdir($directory), rule: 'GENERATION_DIRECTORY_FAILED');
        try {
            GenerationFailure::require(valid: getcwd() === $directory, rule: 'GENERATION_PATH_CHANGED');

            return $operation();
        } finally {
            GenerationFailure::require(valid: chdir($previous), rule: 'GENERATION_DIRECTORY_FAILED');
        }
    }
}
