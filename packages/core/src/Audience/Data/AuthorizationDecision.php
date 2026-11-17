<?php

declare(strict_types=1);

namespace SocKit\Audience\Data;

use Packman\Attributes\Api;
use SocKit\Audience\Exceptions\AuthorizationDeniedException;

/**
 * Tri-state authorization verdict: Allow, Deny, or Unknown (fail-closed).
 */
#[Api(since: '1.0')]
final readonly class AuthorizationDecision
{
    private function __construct(
        public string $state,
        public string $reason,
    ) {}

    public static function allow(string $reason = 'allowed'): self
    {
        return new self('allow', $reason);
    }

    public static function deny(string $reason): self
    {
        return new self('deny', $reason);
    }

    public static function unknown(string $reason = 'authorization.unknown'): self
    {
        return new self('unknown', $reason);
    }

    public function isAllowed(): bool
    {
        return $this->state === 'allow';
    }

    public function isUnknown(): bool
    {
        return $this->state === 'unknown';
    }

    public function requireAllowed(): void
    {
        if ($this->state === 'allow') {
            return;
        }

        if ($this->state === 'unknown') {
            throw AuthorizationDeniedException::unknown($this->reason);
        }

        throw AuthorizationDeniedException::denied($this->reason);
    }
}
