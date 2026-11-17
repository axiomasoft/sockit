<?php

declare(strict_types=1);

namespace SocKit\Database\Internal;

use DateTimeImmutable;
use Packman\Attributes\Internal;

/**
 * Type-safe accessor helper for stdClass/object rows returned by Laravel's Query Builder.
 */
#[Internal]
final class SockitRows
{
    public static function str(object $row, string $column, string $default = ''): string
    {
        $props = get_object_vars($row);
        $val = $props[$column] ?? $default;

        return is_string($val) ? $val : (is_int($val) || is_float($val) ? (string) $val : $default);
    }

    public static function optStr(object $row, string $column): ?string
    {
        $props = get_object_vars($row);
        $val = $props[$column] ?? null;

        if ($val === null) {
            return null;
        }

        return is_string($val) ? $val : (is_int($val) || is_float($val) ? (string) $val : null);
    }

    public static function int(object $row, string $column, int $default = 0): int
    {
        $props = get_object_vars($row);
        $val = $props[$column] ?? $default;

        return is_int($val) ? $val : (is_numeric($val) ? (int) $val : $default);
    }

    public static function date(object $row, string $column): DateTimeImmutable
    {
        return new DateTimeImmutable(self::str($row, $column, 'now'));
    }

    public static function optDate(object $row, string $column): ?DateTimeImmutable
    {
        $raw = self::optStr($row, $column);

        return $raw !== null ? new DateTimeImmutable($raw) : null;
    }

    /** @return array<array-key, mixed> */
    public static function jsonArray(object $row, string $column): array
    {
        $raw = self::str($row, $column, '{}');
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
