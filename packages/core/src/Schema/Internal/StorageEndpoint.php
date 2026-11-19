<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

use Packman\Attributes\Internal;

/** No DNS lookup: equivalence beyond spelling is a host deployment assertion. */
#[Internal]
final class StorageEndpoint
{
    public static function normalize(mixed $host, mixed $port = 5432): string
    {
        SchemaValues::require(valid: is_string($host) && $host !== '' && (is_int($port) || (is_string($port) && ctype_digit($port))), key: 'endpoint', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');
        $port = (int) $port;
        SchemaValues::require(valid: $port > 0 && $port <= 65535, key: 'endpoint', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');
        if (str_starts_with($host, '/')) {
            SchemaValues::require(valid: preg_match('~^/[a-zA-Z0-9_./-]+$~D', $host) === 1, key: 'endpoint', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');
            $parts = array_values(array_filter(explode('/', $host), fn (string $part): bool => $part !== '' && $part !== '.'));
            SchemaValues::require(valid: $parts !== [] && ! in_array('..', $parts, true), key: 'endpoint', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');

            return 'unix:/'.implode('/', $parts).':'.$port;
        }
        $host = strtolower(rtrim($host, '.'));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $binary = inet_pton($host);
            assert($binary !== false);
            $host = '['.inet_ntop($binary).']';
        } else {
            SchemaValues::require(valid: strlen($host) <= 253 && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host) === 1, key: 'endpoint', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');
            foreach (explode('.', $host) as $label) {
                SchemaValues::require(valid: strlen($label) <= 63 && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) === 1, key: 'endpoint', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');
            }
        }

        return 'tcp:'.$host.':'.$port;
    }

    public static function fromArray(mixed $data): string
    {
        SchemaValues::require(valid: is_array($data) && array_diff(array_keys($data), ['host', 'port']) === [] && isset($data['host']), key: 'endpoint', rule: 'STORAGE_ENDPOINT_AMBIGUOUS');

        return self::normalize(host: $data['host'], port: $data['port'] ?? 5432);
    }
}
