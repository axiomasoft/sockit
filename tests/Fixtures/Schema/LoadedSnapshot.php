<?php

declare(strict_types=1);

namespace SocKit\Tests\Fixtures\Schema;

use Closure;
use Illuminate\Database\Migrations\Migration;
use ReflectionClass;
use RuntimeException;

/** Checked invoker: Laravel's abstract Migration does not declare up/down methods. */
final readonly class LoadedSnapshot
{
    private Migration $migration;

    public function __construct(string $path)
    {
        $migration = require $path;
        if (! $migration instanceof Migration || ! is_callable([$migration, 'up']) || ! is_callable([$migration, 'down'])) {
            throw new RuntimeException('Invalid generated migration');
        }
        $this->migration = $migration;
    }

    public function constant(string $name): string
    {
        $value = new ReflectionClass($this->migration)->getConstant($name);
        if (! is_string($value)) {
            throw new RuntimeException('Missing snapshot constant');
        }

        return $value;
    }

    public function up(): void
    {
        $this->invoke('up');
    }

    public function down(): void
    {
        $this->invoke('down');
    }

    private function invoke(string $action): void
    {
        $call = [$this->migration, $action];
        if (! is_callable($call)) {
            throw new RuntimeException('Missing generated action');
        }
        Closure::fromCallable($call)();
    }
}
