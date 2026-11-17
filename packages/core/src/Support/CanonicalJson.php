<?php

declare(strict_types=1);

namespace SocKit\Support;

use JsonException;
use Packman\Attributes\Api;
use SocKit\Support\Exceptions\InvalidPayloadException;

/**
 * Deterministic canonical JSON encoder for payload hashing (rejects floats, objects, and resources).
 */
#[Api(since: '1.0')]
final class CanonicalJson
{
    /** @param array<array-key, mixed> $value */
    public static function encode(array $value): string
    {
        if ($value === []) {
            return '{}';
        }

        if (array_is_list($value)) {
            throw InvalidPayloadException::invalid('Canonical JSON root must be an associative map or empty array.');
        }

        $normalized = self::normalizeValue($value, '');

        try {
            return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidPayloadException::invalid('Failed to encode canonical JSON: '.$exception->getMessage());
        }
    }

    private static function normalizeValue(mixed $value, string $pointer): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            throw InvalidPayloadException::invalid('Floating-point numbers are forbidden in canonical payloads.', [], $pointer === '' ? null : $pointer);
        }

        if (! is_array($value)) {
            throw InvalidPayloadException::invalid('Unsupported value type ['.get_debug_type($value).'] in canonical payload.', [], $pointer === '' ? null : $pointer);
        }

        if ($value === []) {
            return [];
        }

        if (array_is_list($value)) {
            $result = [];
            foreach ($value as $index => $item) {
                $result[] = self::normalizeValue($item, $pointer.'/'.$index);
            }

            return $result;
        }

        $sorted = [];
        foreach ($value as $key => $item) {
            $keyString = (string) $key;
            $escaped = str_replace(['~', '/'], ['~0', '~1'], $keyString);
            $sorted[$keyString] = self::normalizeValue($item, $pointer.'/'.$escaped);
        }

        ksort($sorted, SORT_STRING);

        return $sorted;
    }
}
