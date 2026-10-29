<?php

declare(strict_types=1);

namespace SocKit\Panels\Internal;

use SocKit\Panels\Exceptions\InvalidPanelException;

final class PanelValues
{
    /** @phpstan-assert true $valid */
    public static function require(bool $valid, string $key, string $rule): void
    {
        if (! $valid) {
            throw new InvalidPanelException(message: 'Invalid SocKit panel declaration', detail: ['panel' => '', 'provider' => '', 'key' => $key, 'rule' => $rule]);
        }
    }

    /** Copy by value at every level, stripping PHP references as well as rejecting objects.
     * @param array<array-key, mixed> $values
     * @return array<array-key, scalar|array<array-key, mixed>|null> */
    public static function copy(array $values, string $key): array
    {
        $result = [];
        foreach ($values as $name => $value) {
            self::require(valid: is_array($value) || $value === null || is_scalar($value), key: $key.'.'.$name, rule: 'PANEL_SETTING_INVALID');
            self::require(valid: ! is_float($value) || is_finite($value), key: $key.'.'.$name, rule: 'PANEL_SETTING_INVALID');
            $result[$name] = is_array($value) ? self::copy(values: $value, key: $key.'.'.$name) : $value;
        }

        return $result;
    }

    public static function identifier(string $value): bool
    {
        return strlen($value) <= 63 && preg_match('~^[a-z_][a-z0-9_]*$~D', $value) === 1;
    }
}
