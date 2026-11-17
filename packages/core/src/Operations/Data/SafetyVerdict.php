<?php

declare(strict_types=1);

namespace SocKit\Operations\Data;

use Packman\Attributes\Api;

/**
 * Content safety prescreen verdict (`allow` | `hold` | `reject`).
 */
#[Api(since: '1.0')]
final readonly class SafetyVerdict
{
    private function __construct(
        public string $decision,
        public string $reason,
    ) {}

    public static function allow(string $reason = 'safety.allowed'): self
    {
        return new self('allow', $reason);
    }

    public static function hold(string $reason = 'safety.hold'): self
    {
        return new self('hold', $reason);
    }

    public static function reject(string $reason = 'safety.rejected'): self
    {
        return new self('reject', $reason);
    }
}
