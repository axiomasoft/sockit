<?php

declare(strict_types=1);

namespace SocKit\Support;

use SocKit\Attributes\Api;
use SocKit\Support\Exceptions\InvalidCanonicalPayloadException;

#[Api(since: '1.0')]
final class CanonicalJson
{
    /** @param array<mixed> $value */
    public static function encode(array $value): string
    {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidCanonicalPayloadException('Root must be an object');
        }
        try {
            return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new InvalidCanonicalPayloadException(message: 'Invalid JSON payload', previous: $e);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            } foreach ($value as $key => $item) {
                $value[$key] = self::normalize($item);
            }

            return $value;
        }
        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new InvalidCanonicalPayloadException('Unsupported payload value');
        }

        return $value;
    }
}
