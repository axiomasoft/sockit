<?php

declare(strict_types=1);

namespace SocKit\Configuration\Data;

use SocKit\Attributes\Api;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;

#[Api(since: '1.0')]
final readonly class ModulesConfig
{
    /** @param list<string> $enabled
     * @param array<string, array<string, scalar|array<array-key, mixed>|null>> $settings */
    public function __construct(public array $enabled, public array $settings) {}

    /** Maps merge recursively; lists (including empty lists) replace the previous value.
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $overrides
     * @return array<array-key, mixed> */
    public static function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $defaults[$key] = is_array($value) && ! array_is_list($value) && isset($defaults[$key]) && is_array($defaults[$key]) && ! array_is_list($defaults[$key])
                ? self::merge(defaults: $defaults[$key], overrides: $value) : $value;
        }

        return $defaults;
    }

    /** @return array<string, scalar|array<array-key, mixed>|null> */
    public static function settingsMap(mixed $value, string $key): array
    {
        self::assertSerializable(value: $value, key: $key);
        if (! is_array($value)) {
            throw new InvalidConfigurationException(message: 'Settings must be a map', detail: ['key' => $key, 'rule' => 'CONFIG_MAP']);
        }
        $settings = [];
        foreach ($value as $name => $item) {
            if (! is_string($name) || (! is_scalar($item) && ! is_array($item) && $item !== null)) {
                throw new InvalidConfigurationException(message: 'Settings must be a serializable map', detail: ['key' => $key, 'rule' => 'CONFIG_MAP']);
            }
            $settings[$name] = is_array($item) ? self::copy($item) : $item;
        }

        return $settings;
    }

    /** Copy validated values recursively so no nested PHP reference survives normalization.
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed> */
    private static function copy(array $values): array
    {
        $copy = [];
        foreach ($values as $key => $value) {
            $copy[$key] = is_array($value) ? self::copy($value) : $value;
        }

        return $copy;
    }

    public static function assertSerializable(mixed $value, string $key): void
    {
        if (is_array($value)) {
            foreach ($value as $child => $item) {
                self::assertSerializable(value: $item, key: $key.'.'.$child);
            }
        } elseif ($value !== null && ! is_scalar($value) || is_float($value) && ! is_finite($value)) {
            throw new InvalidConfigurationException(message: 'Configuration must contain finite scalars and arrays', detail: ['key' => $key, 'rule' => 'CONFIG_SERIALIZABLE']);
        }
    }
}
