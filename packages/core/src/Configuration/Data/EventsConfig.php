<?php

declare(strict_types=1);

namespace SocKit\Configuration\Data;

use Packman\Attributes\Api;
use SocKit\Configuration\Exceptions\InvalidConfigurationException;

/** Server-owned source epoch. It is unrelated to actor generations and client payloads. */
#[Api(since: '1.0')]
final readonly class EventsConfig
{
    public function __construct(public string $sourceGeneration = '1', public int $leaseSeconds = 30, public int $retrySeconds = 5, public int $maxAttempts = 10)
    {
        if ($sourceGeneration === '' || strlen($sourceGeneration) > 64 || preg_match('/[\x00-\x1f\x7f]/', $sourceGeneration)
            || $leaseSeconds < 1 || $leaseSeconds > 3600 || $retrySeconds < 1 || $retrySeconds > 86400 || $maxAttempts < 1 || $maxAttempts > 1000) {
            throw new InvalidConfigurationException(message: 'Invalid event source or relay configuration', detail: ['key' => 'sockit.events', 'rule' => 'EVENTS_CONFIG_INVALID']);
        }
    }
}
