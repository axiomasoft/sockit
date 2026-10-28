<?php

declare(strict_types=1);

namespace SocKit\Schema\Internal;

/** Trusted, config-cache-safe host assertion; never inferred from a connection name. */
final readonly class StorageDeployment
{
    /** @param list<string> $aliases */
    private function __construct(public string $cluster, public string $endpoint, public array $aliases) {}

    public static function fromArray(mixed $data): self
    {
        SchemaValues::require(valid: is_array($data) && array_diff(array_keys($data), ['cluster', 'endpoint', 'aliases']) === [], key: 'deployment', rule: 'STORAGE_DEPLOYMENT_INVALID');
        $cluster = $data['cluster'] ?? null;
        SchemaValues::require(valid: is_string($cluster) && preg_match('/^[a-z0-9][a-z0-9_.-]{0,95}$/D', $cluster) === 1, key: 'deployment', rule: 'STORAGE_DEPLOYMENT_INVALID');
        $endpoint = StorageEndpoint::fromArray($data['endpoint'] ?? null);
        $aliases = $data['aliases'] ?? [];
        SchemaValues::require(valid: is_array($aliases) && array_is_list($aliases), key: 'deployment', rule: 'STORAGE_DEPLOYMENT_INVALID');
        $normalized = array_map(StorageEndpoint::fromArray(...), $aliases);
        $normalized = array_values(array_unique(array_diff($normalized, [$endpoint])));
        sort($normalized, SORT_STRING);

        return new self(cluster: $cluster, endpoint: $endpoint, aliases: $normalized);
    }

    public function admits(string $endpoint): bool
    {
        return in_array($endpoint, $this->endpoints(), true);
    }

    /** @return list<string> */
    public function endpoints(): array
    {
        return [$this->endpoint, ...$this->aliases];
    }

    /** Detect inconsistent cluster/endpoint assignments, including across connection aliases.
     * @param list<self> $deployments */
    public static function assertConsistent(array $deployments): void
    {
        $clusters = $endpoints = [];
        foreach ($deployments as $deployment) {
            $cluster = $deployment->cluster;
            SchemaValues::require(valid: ! isset($clusters[$cluster]) || $clusters[$cluster] === $deployment->endpoint, key: 'deployment', rule: 'STORAGE_DEPLOYMENT_DISAGREEMENT');
            $clusters[$cluster] = $deployment->endpoint;
            foreach ($deployment->endpoints() as $endpoint) {
                SchemaValues::require(valid: ! isset($endpoints[$endpoint]) || $endpoints[$endpoint] === $cluster, key: 'deployment', rule: 'STORAGE_DEPLOYMENT_DISAGREEMENT');
                $endpoints[$endpoint] = $cluster;
            }
        }
    }

    /** Validate the whole declared registry without PDO or retaining raw config values. */
    public static function validateRegistry(mixed $data): void
    {
        SchemaValues::require(valid: is_array($data) && ($data === [] || ! array_is_list($data)), key: 'storage.deployments', rule: 'STORAGE_DEPLOYMENT_INVALID');
        $deployments = [];
        foreach ($data as $name => $descriptor) {
            SchemaValues::require(valid: is_string($name) && preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,95}$/D', $name) === 1, key: 'storage.deployments', rule: 'STORAGE_DEPLOYMENT_INVALID');
            $deployments[] = self::fromArray($descriptor);
        }
        self::assertConsistent($deployments);
    }
}
