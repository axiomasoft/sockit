<?php

declare(strict_types=1);

namespace SocKit\Internal\Generation;

final class GenerationFailure extends \RuntimeException
{
    /** @param list<string> $recovery */
    public function __construct(string $rule, public readonly array $recovery = [])
    {
        parent::__construct($rule);
    }

    /** @phpstan-assert true $valid */
    public static function require(bool $valid, string $rule): void
    {
        if (! $valid) {
            throw new self($rule);
        }
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        self::require(valid: is_array($value), rule: 'GENERATION_CONFIG_MAP_INVALID');
        $map = [];
        foreach ($value as $key => $entry) {
            self::require(valid: is_string($key), rule: 'GENERATION_CONFIG_MAP_INVALID');
            $map[$key] = $entry;
        }

        return $map;
    }
}
