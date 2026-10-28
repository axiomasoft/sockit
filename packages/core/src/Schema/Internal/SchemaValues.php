<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use SocKit\Schema\Exceptions\InvalidSchemaException;

final class SchemaValues
{
    /** @phpstan-assert true $valid */
    public static function require(bool $valid, string $key, string $rule = 'SCHEMA_INVALID'): void
    {
        if (! $valid) {
            throw new InvalidSchemaException(message: 'Invalid SocKit schema declaration', detail: ['key' => $key, 'rule' => $rule]);
        }
    }

    public static function identifier(string $value): bool
    {
        return strlen($value) <= 63 && preg_match('/^[a-z_][a-z0-9_]*$/D', $value) === 1;
    }

    /** Strip references recursively and sort maps; ordered lists retain their order. */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            self::require(valid: $value === null || is_scalar($value), key: 'descriptor');
            self::require(valid: ! is_float($value) || is_finite($value), key: 'descriptor');

            return $value;
        }
        $copy = [];
        foreach ($value as $key => $item) {
            $copy[$key] = self::canonical($item);
        }
        if (! array_is_list($copy)) {
            ksort($copy, SORT_STRING);
        }

        return $copy;
    }

    public static function json(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
